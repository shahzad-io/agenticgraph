<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Graph;

use Illuminate\Container\Container;
use Shahzad\AgenticGraph\Contracts\NodeInterface;
use Shahzad\AgenticGraph\Contracts\RouterInterface;
use Shahzad\AgenticGraph\Tracing\Annotations;

class GraphBuilder
{
    protected string $name;

    protected string $description = '';

    protected ?string $entryPoint = null;

    /** @var array<int, string> */
    protected array $exitPoints = [];

    /** @var array<string, string> */
    protected array $nodes = [];

    /** @var array<int, GraphEdge> */
    protected array $edges = [];

    /** @var array<string, mixed> */
    protected array $metadata = [];

    /** @var array<string, string> */
    protected array $joins = [];

    protected ?string $label = null;

    /** @var array{headline: string, fields: list<array{path: string, label?: string}>}|null */
    protected ?array $outcome = null;

    /** @var array<string, bool|int|float> */
    protected array $expectations = [];

    /** @var array<string, array<string, bool>> */
    protected array $toolExpectations = [];

    /** @var array<string, array{label?: string, description?: string, human_input?: list<string>}> */
    protected array $nodeAnnotations = [];

    public static function create(string $name): self
    {
        $i = new self;
        $i->name = $name;

        return $i;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * label, description and humanInput annotate the node in traces; they win per field over the
     * class's #[Annotate].
     *
     * @param  list<string>|null  $humanInput
     */
    public function addNode(string $name, string $class, ?string $label = null, ?string $description = null, ?array $humanInput = null): self
    {
        $this->validateNodeName($name);
        $this->validateNodeClass($class);

        if (isset($this->nodes[$name])) {
            throw new InvalidGraphException("Node '{$name}' already exists in graph");
        }

        $this->nodes[$name] = $class;
        $annotations = $this->nodeAnnotation("Node '{$name}'", $label, $description, $humanInput);
        if ($annotations !== []) {
            $this->nodeAnnotations[$name] = $annotations;
        }

        return $this;
    }

    /**
     * The graph's label in traces. The graph block (label and description()) is sent only when this is set.
     */
    public function label(string $label): self
    {
        $this->checkText('Graph label', $label, 60, placeholders: false);
        $this->label = $label;

        return $this;
    }

    /**
     * What the run produced, for traces: a headline template and up to 8 state paths, each as
     * 'path' or 'path' => 'Display label'.
     *
     * @param  array<int|string, mixed>  $fields
     */
    public function outcome(string $headline, array $fields = []): self
    {
        $this->checkText('Outcome headline', $headline, 200);
        if (count($fields) > 8) {
            throw new InvalidGraphException('Outcome has '.count($fields).' fields; at most 8 are allowed');
        }

        $declared = [];
        foreach ($fields as $key => $value) {
            [$path, $label] = is_int($key) ? [$value, null] : [$key, $value];
            if (! is_string($path) || ! Annotations::isPath($path)) {
                throw new InvalidGraphException('Outcome field '.var_export($path, true).' is not a state path (letters, digits, _ and - separated by dots)');
            }
            if ($label !== null && ! is_string($label)) {
                throw new InvalidGraphException("Outcome field '{$path}' label must be a string");
            }
            $declared[] = ['path' => $path] + ($label !== null ? ['label' => $label] : []);
        }

        $this->outcome = ['headline' => $headline, 'fields' => $declared];

        return $this;
    }

    /**
     * What the run is expected to do, sent with the trace for AgenticPulse to check. Declarations only:
     * they never stop or change a run (the loop limit is execution.loop.max_iterations).
     *
     * @param  array<string, bool|int|float>  $expectations
     */
    public function expectations(array $expectations): self
    {
        $this->expectations = array_merge($this->expectations, $this->checkExpectations('Graph', $expectations, Annotations::GRAPH_EXPECTATIONS));

        return $this;
    }

    /**
     * Expectations for one tool by its name, sent on that tool's spans.
     *
     * @param  array<string, bool>  $expectations
     */
    public function toolExpectations(string $tool, array $expectations): self
    {
        if (trim($tool) === '') {
            throw new InvalidGraphException('Tool name cannot be empty');
        }

        /** @var array<string, bool> $checked */
        $checked = $this->checkExpectations("Tool '{$tool}'", $expectations, Annotations::TOOL_EXPECTATIONS);
        $this->toolExpectations[$tool] = array_merge($this->toolExpectations[$tool] ?? [], $checked);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function addEdge(string $source, string $target, array $metadata = []): self
    {
        $this->validateNodeReference($source, 'source');
        $this->validateNodeReference($target, 'target');

        // Check for duplicate edges
        foreach ($this->edges as $edge) {
            if ($edge->getSource() === $source && $edge->getTarget() === $target) {
                throw new InvalidGraphException(
                    "Duplicate edge from '{$source}' to '{$target}'"
                );
            }
        }

        $this->edges[] = new GraphEdge($source, $target, null, $metadata);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function addConditionalEdge(string $source, RouterInterface|string $router, array $metadata = []): self
    {
        $this->validateNodeReference($source, 'source');

        if (is_string($router)) {
            if (! class_exists($router)) {
                throw new InvalidGraphException(
                    "Router class does not exist: {$router}"
                );
            }
            if (! in_array(RouterInterface::class, class_implements($router) ?: [], true)) {
                throw new InvalidGraphException(
                    "Router class must implement RouterInterface: {$router}"
                );
            }
        }

        $condition = $router;
        $this->edges[] = new GraphEdge($source, [], $condition, $metadata);

        return $this;
    }

    public function setEntryPoint(string $name): self
    {
        $this->validateNodeReference($name, 'entry point');
        $this->entryPoint = $name;

        return $this;
    }

    public function addExitPoint(string $name): self
    {
        $this->validateNodeReference($name, 'exit point');

        if (in_array($name, $this->exitPoints, true)) {
            throw new InvalidGraphException(
                "Exit point '{$name}' already added"
            );
        }

        $this->exitPoints[] = $name;

        return $this;
    }

    public function withMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    public function build(): Graph
    {
        $this->validateBeforeBuild();
        $annotations = $this->annotations();

        $container = Container::getInstance();
        $instances = [];
        foreach ($this->nodes as $name => $class) {
            $node = $container->make($class);
            $instances[$name] = $node;
        }

        $hasCycles = ! empty((new Graph(
            $this->name,
            $this->description,
            $this->entryPoint ?? '',
            $this->exitPoints,
            $instances,
            $this->edges,
            $this->metadata,
            $this->joins,
        ))->detectCycles());

        $metadata = array_merge($this->metadata, ['has_cycles' => $hasCycles]);

        $graph = new Graph(
            $this->name,
            $this->description,
            $this->entryPoint ?? '',
            $this->exitPoints,
            $instances,
            $this->edges,
            $metadata,
            $this->joins,
            $annotations,
        );
        $graph->validate();

        return $graph;
    }

    /**
     * @param  array<int, string>  $sources
     */
    public function addJoin(array $sources, string $target): self
    {
        $this->validateNodeReference($target, 'join target');

        if (count($sources) < 2) {
            throw new InvalidGraphException(
                'Join requires at least 2 source nodes'
            );
        }

        $uniqueSources = array_unique($sources);
        if (count($uniqueSources) !== count($sources)) {
            throw new InvalidGraphException(
                'Join sources must be unique'
            );
        }

        foreach ($sources as $source) {
            $this->validateNodeReference($source, 'join source');
        }

        $sourcesSorted = $sources;
        sort($sourcesSorted);
        $key = implode('|', $sourcesSorted);

        if (isset($this->joins[$key])) {
            throw new InvalidGraphException(
                "Join for sources '{$key}' already exists"
            );
        }

        $this->joins[$key] = $target;

        return $this;
    }

    /**
     * The annotations the Graph carries: builder declarations, with each node's #[Annotate] under its
     * fluent fields.
     *
     * @return array<string, mixed>
     */
    private function annotations(): array
    {
        $nodes = [];
        foreach ($this->nodes as $name => $class) {
            $attribute = (new \ReflectionClass($class))->getAttributes(Annotate::class)[0] ?? null;
            try {
                $declared = $attribute?->newInstance();
            } catch (\Error $e) {
                throw new InvalidGraphException("Invalid #[Annotate] on {$class}: {$e->getMessage()}", 0, $e);
            }

            $node = ($this->nodeAnnotations[$name] ?? [])
                + ($declared !== null ? $this->nodeAnnotation("#[Annotate] on {$class}", $declared->label, $declared->description, $declared->humanInput) : []);
            if ($node !== []) {
                $nodes[$name] = $node;
            }
        }

        return array_filter([
            'label' => $this->label,
            'outcome' => $this->outcome,
            'expectations' => $this->expectations,
            'tools' => $this->toolExpectations,
            'nodes' => $nodes,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<int|string, mixed>|null  $humanInput
     * @return array{label?: string, description?: string, human_input?: list<string>}
     */
    private function nodeAnnotation(string $where, ?string $label, ?string $description, ?array $humanInput): array
    {
        $annotation = [];
        if ($label !== null) {
            $this->checkText("{$where} label", $label, 60);
            $annotation['label'] = $label;
        }
        if ($description !== null) {
            $this->checkText("{$where} description", $description, 200);
            $annotation['description'] = $description;
        }
        if ($humanInput !== null) {
            if (count($humanInput) > 8) {
                throw new InvalidGraphException("{$where} has ".count($humanInput).' human input keys; at most 8 are allowed');
            }
            foreach ($humanInput as $key) {
                if (! is_string($key) || ! Annotations::isPath($key)) {
                    throw new InvalidGraphException("{$where} human input key ".var_export($key, true).' is not a state path (letters, digits, _ and - separated by dots)');
                }
            }
            $annotation['human_input'] = array_values(array_unique($humanInput));
        }

        return $annotation;
    }

    private function checkText(string $where, string $text, int $max, bool $placeholders = true): void
    {
        if (mb_strlen($text) > $max) {
            throw new InvalidGraphException("{$where} is ".mb_strlen($text)." characters; at most {$max} are allowed");
        }
        if ($placeholders && ($problem = Annotations::syntaxError($text)) !== null) {
            throw new InvalidGraphException("{$where} has invalid placeholder syntax: {$problem}");
        }
    }

    /**
     * @param  array<int|string, mixed>  $expectations
     * @param  array<string, string>  $known  name => bool, int or float
     * @return array<string, bool|int|float>
     */
    private function checkExpectations(string $where, array $expectations, array $known): array
    {
        $checked = [];
        foreach ($expectations as $name => $value) {
            $type = $known[$name] ?? null;
            if ($type === null) {
                throw new InvalidGraphException("{$where} expectation '{$name}' is unknown; known: ".implode(', ', array_keys($known)));
            }

            $valid = match ($type) {
                'bool' => is_bool($value),
                'int' => is_int($value) && $value >= 0,
                default => (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0,
            };
            if (! $valid) {
                $expected = ['bool' => 'a bool', 'int' => 'an int, at least 0', 'float' => 'a number, at least 0'][$type];
                throw new InvalidGraphException("{$where} expectation '{$name}' must be {$expected}, got ".get_debug_type($value));
            }

            $checked[(string) $name] = $type === 'float' ? (float) $value : $value;
        }

        return $checked;
    }

    /**
     * Validate node name format.
     */
    private function validateNodeName(string $name): void
    {
        if (trim($name) === '') {
            throw new InvalidGraphException('Node name cannot be empty');
        }

        if (! preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            throw new InvalidGraphException(
                "Invalid node name '{$name}'. Use only alphanumeric characters, underscores, and hyphens."
            );
        }
    }

    /**
     * Validate node class exists and implements correct interface.
     */
    private function validateNodeClass(string $class): void
    {
        if (! class_exists($class)) {
            throw new InvalidGraphException("Node class does not exist: {$class}");
        }

        $requiredInterface = NodeInterface::class;
        if (! in_array($requiredInterface, class_implements($class) ?: [], true)) {
            throw new InvalidGraphException(
                "Node class must implement NodeInterface: {$class}"
            );
        }
    }

    /**
     * Validate that a node reference exists in the builder.
     */
    private function validateNodeReference(string $name, string $context): void
    {
        if (! isset($this->nodes[$name])) {
            throw new InvalidGraphException(
                "{$context} references non-existent node: '{$name}'"
            );
        }
    }

    /**
     * Validate builder state before building the graph.
     */
    private function validateBeforeBuild(): void
    {
        // Validate name
        if (trim($this->name) === '') {
            throw new InvalidGraphException('Graph name cannot be empty');
        }

        // Validate we have nodes
        if (empty($this->nodes)) {
            throw new InvalidGraphException('Graph must have at least one node');
        }

        // Validate entry point is set
        if ($this->entryPoint === null) {
            throw new InvalidGraphException('Graph must have an entry point. Call setEntryPoint().');
        }

        // Validate entry point exists in nodes
        if (! isset($this->nodes[$this->entryPoint])) {
            throw new InvalidGraphException(
                "Entry point '{$this->entryPoint}' does not exist in nodes"
            );
        }

        // Validate exit points
        if (empty($this->exitPoints)) {
            throw new InvalidGraphException('Graph must have at least one exit point');
        }

        foreach ($this->exitPoints as $exitPoint) {
            if (! isset($this->nodes[$exitPoint])) {
                throw new InvalidGraphException(
                    "Exit point '{$exitPoint}' does not exist in nodes"
                );
            }
        }

        // Validate all edges reference existing nodes
        foreach ($this->edges as $edge) {
            $source = $edge->getSource();
            if (! isset($this->nodes[$source])) {
                throw new InvalidGraphException(
                    "Edge source '{$source}' does not exist in nodes"
                );
            }

            $target = $edge->getTarget();
            if (is_string($target) && ! isset($this->nodes[$target])) {
                throw new InvalidGraphException(
                    "Edge target '{$target}' does not exist in nodes"
                );
            }
        }
    }
}
