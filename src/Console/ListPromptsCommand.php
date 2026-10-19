<?php

declare(strict_types=1);

namespace Shahzad\AgenticGraph\Console;

use Illuminate\Console\Command;
use Shahzad\AgenticGraph\Prompts\Exceptions\TemplateException;
use Shahzad\AgenticGraph\Prompts\Prompts;

class ListPromptsCommand extends Command
{
    protected $signature = 'agenticgraph:prompts';

    protected $description = 'List prompt files with name, version and hash; fails when any file is invalid (for CI)';

    public function handle(): int
    {
        $root = Prompts::path();
        if (! is_dir($root)) {
            $this->warn("No prompt directory at {$root}");

            return self::SUCCESS;
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'md') {
                $files[] = substr($file->getPathname(), strlen($root) + 1, -3);
            }
        }
        sort($files);

        $rows = [];
        $errors = [];
        foreach ($files as $relative) {
            $name = str_replace(DIRECTORY_SEPARATOR, '.', $relative);
            try {
                $prompt = Prompts::get($name);
                $rows[] = [$prompt->name, $prompt->version, $prompt->hash];
            } catch (TemplateException $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($rows !== []) {
            $this->table(['name', 'version', 'hash'], $rows);
        } elseif ($errors === []) {
            $this->warn("No prompt files in {$root}");
        }
        foreach ($errors as $error) {
            $this->error($error);
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
