# Testing traces

Docs: `/docs/guide-testing`, `/docs/tracing`

Leave tracing off unless a test is about traces. `auto` resolves to `null` in unit tests, so nothing is sent by default.

## Enable it at boot

The tracing listener is subscribed when the app boots, and only if tracing is enabled then. Setting `agenticgraph.tracing.enabled` inside a test is too late. Put it in `phpunit.xml`, with the `file` driver:

```xml
<php>
    <env name="AGENTICGRAPH_TRACING" value="true"/>
    <env name="AGENTICGRAPH_TRACING_EXPORTER" value="file"/>
    <env name="AGENTICGRAPH_TRACING_FILE_PATH" value="storage/framework/testing/traces"/>
</php>
```

To pick the driver per test instead, keep `AGENTICGRAPH_TRACING=true` in `phpunit.xml` and rebind the exporter, which is a singleton:

```php
use Shahzad\AgenticGraph\Tracing\Contracts\TracerExporter;

config([
    'agenticgraph.tracing.exporter' => 'file',
    'agenticgraph.tracing.file.path' => $dir,
]);
app()->forgetInstance(TracerExporter::class);
```

## Assert on the envelope

In tests the send is immediate, so the file exists when `run()` returns. One file per segment, under the execution id.

```php
use Illuminate\Support\Facades\File;
use Shahzad\AgenticGraph\Facades\AgenticGraph;
use Shahzad\AgenticGraph\State\State;

it('writes a final trace for the run', function () {
    $dir = config('agenticgraph.tracing.file.path');
    File::deleteDirectory($dir);

    $handle = AgenticGraph::run('content', new State(['topic' => 'Laravel']));

    $files = glob($dir.'/'.$handle->id().'/*.json');
    $envelope = json_decode(file_get_contents($files[0]), true);

    expect($files)->toHaveCount(1)
        ->and($envelope['schema_version'])->toBe('agenticgraph.trace.v1')
        ->and($envelope['segment']['final'])->toBeTrue()
        ->and(array_column($envelope['spans'], 'span_type'))->toContain('graph', 'node');
});
```

- A run that pauses at a gate writes a non-final segment. After `resume()`, a second file is the final one.
- `Event::fake()` stops the tracing listener, so no file is written.
- Mock AgenticPulse with `Http::fake()` and the `pulse` driver when the test is about the transport.
