<?php

use Shahzad\AgenticGraph\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);
// The wire freeze test (kept unchanged) pins the 0.2 public channel name; it freezes the frame body, not the channel.
uses()->beforeEach(fn () => config()->set('agenticgraph.streaming.broadcast.private', false))->in('Unit/Streaming/StreamWireContractFreezeTest.php');
require __DIR__.'/Support/Factories.php';
require __DIR__.'/Support/Streams.php';

// Wire format for every tracing timestamp: UTC with microseconds.
const TRACE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/';
