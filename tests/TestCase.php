<?php

namespace Tests;

use App\Services\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->centralQueries = true;
    }
}
