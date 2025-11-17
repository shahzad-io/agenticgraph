<?php

arch('nodes have Node suffix')
    ->expect('Shahzad\AgenticGraph\Nodes')
    ->classes()
    ->toHaveSuffix('Node');

arch('routers have Router suffix')
    ->expect('Shahzad\AgenticGraph\Routers')
    ->classes()
    ->toHaveSuffix('Router');