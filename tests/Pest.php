<?php

use StringKe\TidbPhp\Laravel\Tests\IntegrationTestCase;
use StringKe\TidbPhp\Laravel\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit');
pest()->extend(IntegrationTestCase::class)->in('Integration');
