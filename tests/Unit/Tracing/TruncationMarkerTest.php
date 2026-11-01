<?php

declare(strict_types=1);

use Shahzad\AgenticGraph\Tracing\CaptureDepth;

it('ends with the marker carrying the original length and is exactly max_length long', function () {
    config(['agenticgraph.tracing.capture.max_length' => 50]);

    $out = CaptureDepth::truncate(str_repeat('x', 100));

    expect($out)->toEndWith(' [...truncated 100]')->and(strlen($out))->toBe(50);
});

it('counts the original length in bytes and cuts at a character boundary', function () {
    config(['agenticgraph.tracing.capture.max_length' => 51]);
    $text = str_repeat('é', 60); // 120 bytes

    $out = CaptureDepth::truncate($text);

    expect($out)->toEndWith(' [...truncated 120]')
        ->and(mb_check_encoding($out, 'UTF-8'))->toBeTrue()
        ->and(strlen($out))->toBeLessThanOrEqual(51)
        ->and(json_encode($out))->not->toBeFalse();
});

it('leaves short text unchanged', function () {
    expect(CaptureDepth::truncate('short'))->toBe('short');
});

it('cuts at a sentence or word boundary on request', function () {
    $out = CaptureDepth::cut(str_repeat('word ', 100), 60, atWords: true);

    expect($out)->toMatch('/^(word )*word \[\.\.\.truncated 500\]$/')->and(strlen($out))->toBeLessThanOrEqual(60);
});

it('is the only place that writes the marker', function () {
    $writers = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src'));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), '[...truncated')) {
            $writers[] = $file->getFilename();
        }
    }

    expect($writers)->toBe(['CaptureDepth.php']);
});
