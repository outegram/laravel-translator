<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Pest automatically discovers tests using the PHPUnit backend.
| The uses() helper applies base TestCase configuration to all tests.
|
*/

uses(Syriable\Translator\Tests\TestCase::class)->in('Unit', 'Feature');
