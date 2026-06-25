<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Prompts;

use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;

class PromptTemplate
{
    protected string $template;

    /** @var array<string, mixed> */
    protected array $defaultVariables;

    /** @var array<int, string> */
    protected array $requiredVariables;

    /**
     * @param  array<string, mixed>  $defaultVariables
     * @param  array<int, string>  $requiredVariables
     */
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

        return TemplateInterpolator::interpolate($this->template, $vars);
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
        return TemplateInterpolator::extractVariables($this->template);
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
