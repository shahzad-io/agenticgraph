<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Prompts;

use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;

/**
 * Loads prompt files: Markdown with front matter under config agenticgraph.prompts.path (default resources/prompts).
 * `support.reply` is `support/reply.md`. Model and settings stay on the node, never in the file.
 */
final class Prompts
{
    private const SETTING_KEYS = ['model', 'settings', 'provider', 'temperature', 'max_tokens', 'top_p', 'reasoning', 'provider_options', 'cache', 'tools'];

    /**
     * Parsed files by content hash. Keyed by content, so an edited file is parsed again and nothing stale is served.
     * ponytail: grows by one entry per distinct file content in the process; fine for files edited by deploys.
     *
     * @var array<string, PromptFile>
     */
    private static array $parsed = [];

    public static function get(string $name): PromptFile
    {
        if (preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)*$/', $name) !== 1) {
            throw new TemplateException("Invalid prompt name [{$name}]: use dotted segments of letters, digits, _ and -.");
        }

        $path = self::path().'/'.str_replace('.', '/', $name).'.md';
        if (! is_file($path)) {
            throw new TemplateException("Prompt file not found: [{$name}] at {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new TemplateException("Failed to read prompt file: {$path}");
        }

        $hash = substr(hash('sha256', $content), 0, 12);

        // A clone: ChatPromptTemplate's builder methods append in place.
        return clone (self::$parsed[$hash] ??= self::parse($name, $content, $hash, $path));
    }

    public static function path(): string
    {
        return rtrim((string) config('agenticgraph.prompts.path', resource_path('prompts')), '/');
    }

    /**
     * @throws TemplateException
     */
    public static function parse(string $name, string $content, string $hash, string $path = ''): PromptFile
    {
        $where = $path !== '' ? $path : $name;
        $content = str_replace("\r\n", "\n", $content);

        if (preg_match('/\A---\n(.*?)\n---(?:\n|\z)(.*)\z/s', $content, $parts) !== 1) {
            throw new TemplateException("Prompt file {$where}: front matter missing (start the file with a --- block).");
        }

        $meta = self::frontMatter($parts[1], $where);
        if (! isset($meta['name'])) {
            throw new TemplateException("Prompt file {$where}: front matter name is missing (expected [{$name}]).");
        }
        if ($meta['name'] !== $name) {
            throw new TemplateException("Prompt file {$where}: front matter name [{$meta['name']}] does not match the path [{$name}].");
        }
        if (($meta['version'] ?? '') === '') {
            throw new TemplateException("Prompt file {$where}: front matter version is missing.");
        }

        return new PromptFile($name, $meta['version'], $hash, self::messages($parts[2], $where), $meta['description'] ?? null);
    }

    /**
     * Flat `key: value` lines only (optionally quoted values); nested YAML is rejected.
     *
     * @return array<string, string>
     */
    private static function frontMatter(string $block, string $where): array
    {
        $meta = [];
        foreach (explode("\n", $block) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*):(.*)$/', $line, $m) !== 1) {
                throw new TemplateException("Prompt file {$where}: front matter line [{$line}] is not a flat key: value.");
            }
            $key = $m[1];
            if (in_array($key, self::SETTING_KEYS, true)) {
                throw new TemplateException("Prompt file {$where}: front matter key [{$key}] is not allowed. Model and settings stay on the node.");
            }
            if (! in_array($key, ['name', 'version', 'description'], true)) {
                throw new TemplateException("Prompt file {$where}: unknown front matter key [{$key}] (allowed: name, version, description).");
            }
            $value = trim($m[2]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $meta[$key] = $value;
        }

        return $meta;
    }

    /**
     * `## system`, `## user`, `## assistant` sections and `## messages: <var>` placeholders. A section's text is
     * kept as written, without the blank lines around it.
     *
     * @return list<Messages\Message>
     */
    private static function messages(string $body, string $where): array
    {
        $chunks = preg_split('/^## (.*)$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($chunks === false) {
            throw new TemplateException("Prompt file {$where}: body could not be read.");
        }
        if (trim($chunks[0]) !== '') {
            throw new TemplateException("Prompt file {$where}: text before the first role heading (## system, ## user, ## assistant).");
        }

        $messages = [];
        for ($i = 1; $i < count($chunks); $i += 2) {
            $heading = trim($chunks[$i]);
            $text = trim($chunks[$i + 1], "\n");

            if (preg_match('/^messages:\s*([A-Za-z_][A-Za-z0-9_]*)$/', $heading, $m) === 1) {
                if (trim($text) !== '') {
                    throw new TemplateException("Prompt file {$where}: ## {$heading} takes no text.");
                }
                $messages[] = new Messages\MessagesPlaceholder($m[1]);

                continue;
            }
            if (! in_array($heading, ['system', 'user', 'assistant'], true)) {
                throw new TemplateException("Prompt file {$where}: unknown heading [## {$heading}] (allowed: system, user, assistant, messages: <var>; use ### inside a section).");
            }
            if (trim($text) === '') {
                throw new TemplateException("Prompt file {$where}: ## {$heading} is empty.");
            }
            $messages[] = match ($heading) {
                'system' => new Messages\SystemMessage($text),
                'user' => new Messages\UserMessage($text),
                'assistant' => new Messages\AssistantMessage($text),
            };
        }

        if ($messages === []) {
            throw new TemplateException("Prompt file {$where}: no role sections (## system, ## user, ## assistant).");
        }

        return $messages;
    }
}
