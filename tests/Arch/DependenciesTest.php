<?php

arch('contracts do not depend on implementations')
    ->expect('Shahzad\AgenticGraph\Contracts')
    ->not->toUse('Shahzad\AgenticGraph\Nodes')
    ->not->toUse('Shahzad\AgenticGraph\Routers')
    ->not->toUse('Shahzad\AgenticGraph\Storage');
