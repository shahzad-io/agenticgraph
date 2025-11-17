<?php

arch('contracts are interfaces')
    ->expect('Shahzad\AgenticGraph\Contracts')
    ->toBeInterfaces();

arch('nodes implement NodeInterface')
    ->expect('Shahzad\AgenticGraph\Nodes')
    ->toImplement('Shahzad\AgenticGraph\Contracts\NodeInterface');

arch('routers implement RouterInterface')
    ->expect('Shahzad\AgenticGraph\Routers')
    ->toImplement('Shahzad\AgenticGraph\Contracts\RouterInterface');

arch('storage drivers implement StorageInterface')
    ->expect('Shahzad\AgenticGraph\Storage')
    ->toImplement('Shahzad\AgenticGraph\Contracts\StorageInterface');