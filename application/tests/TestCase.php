<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(
            \App\Services\amoCRM\AmoCrmRequestThrottle::class,
            new \Tests\Support\FakeAmoCrmRequestThrottle,
        );
        $this->app->instance(
            \App\Services\YClients\YClientsRequestThrottle::class,
            new \Tests\Support\FakeYClientsRequestThrottle,
        );
        config(['cache.yclients_lock_store' => 'array']);
    }
}
