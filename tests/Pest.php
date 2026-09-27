<?php

use Shahzad\AgenticGraph\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);
require __DIR__.'/Support/Factories.php';

// Wire format for every tracing timestamp: UTC with microseconds.
const TRACE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/';
