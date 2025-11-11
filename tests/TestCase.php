<?php

namespace Tests;

use App\Support\PrecisionFromZoom;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        PrecisionFromZoom::refresh();
    }
}
