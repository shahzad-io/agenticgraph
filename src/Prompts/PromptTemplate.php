<?php

namespace Shahzad\AgenticGraph\Prompts;

use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;

class PromptTemplate
{
    protected string $template;

    protected array $defaultVariables;

    protected array $requiredVariables;

    public function __construct(
        string $template,
        array $defaultVariables = [],
        array $requiredVariables = []
    ) {
        $this->template = $template;
        $this->defaultVariables = $defaultVariables;
        $this->requiredVariables = $requiredVariables;
    }

    /**
     * Format the template with provided variables
     *
     * @param  array<string, mixed>  $variables
     *
     * @throws TemplateException
     */
    public function format(array $variables = []): string
    {
        $vars = array_merge($this->defaultVariables, $variables);

        // Validate required variables
        $this->validateRequiredVariables($vars);

        // Replace variables in template
        return preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($vars) {
            $key = $matches[1];

            if (! array_key_exists($key, $vars)) {
                throw new TemplateException("Missing template variable: {$key}");
            }

            return $this->formatValue($vars[$key]);
        }, $this->template);
    }

    /**
     * Format a value for insertion into template
     */
    protected function formatValue(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return '';
        }

        return (string) $value;
    }

    /**
     * Validate that all required variables are provided
     *
     * @param  array<string, mixed>  $variables
     *
     * @throws TemplateException
     */
    protected function validateRequiredVariables(array $variables): void
    {
        $missing = array_diff($this->requiredVariables, array_keys($variables));

        if (! empty($missing)) {
            throw new TemplateException(
                'Missing required template variables: '.implode(', ', $missing)
            );
        }
    }

    /**
     * Get all variable names used in the template
     *
     * @return array<int, string>
     */
    public function getVariables(): array
    {
        preg_match_all('/\{(\w+)\}/', $this->template, $matches);

        return array_unique($matches[1]);
    }

    /**
     * Set default variables
     *
     * @param  array<string, mixed>  $variables
     */
    public function withDefaults(array $variables): self
    {
        $this->defaultVariables = array_merge($this->defaultVariables, $variables);

        return $this;
    }

    /**
     * Set required variables
     *
     * @param  array<int, string>  $variables
     */
    public function withRequired(array $variables): self
    {
        $this->requiredVariables = array_merge($this->requiredVariables, $variables);

        return $this;
    }

    /**
     * Get the raw template string
     */
    public function getTemplate(): string
    {
        return $this->template;
    }

    /**
     * Create a new template instance
     */
    public static function create(string $template): self
    {
        return new self($template);
    }

    /**
     * Create a template from a file
     *
     * @throws TemplateException
     */
    public static function fromFile(string $path): self
    {
        if (! file_exists($path)) {
            throw new TemplateException("Template file not found: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new TemplateException("Failed to read template file: {$path}");
        }

        return new self($content);
    }

    /**
     * Convert template to string
     */
    public function __toString(): string
    {
        return $this->template;
    }
}
