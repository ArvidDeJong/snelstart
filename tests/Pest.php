<?php

use Darvis\Snelstart\Tests\TestCase;

// tests/Unit is the plain PHP client and runs without Laravel; tests/Laravel is the integration.
uses(TestCase::class)->in('Laravel');
