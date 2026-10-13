<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\GuardsTestDatabase;

abstract class TestCase extends BaseTestCase
{
    use GuardsTestDatabase;

    protected function setUpTraits()
    {
        // Must run before RefreshDatabase/DatabaseTransactions touch the database.
        $this->assertIsolatedTestDatabase();

        return parent::setUpTraits();
    }
}
