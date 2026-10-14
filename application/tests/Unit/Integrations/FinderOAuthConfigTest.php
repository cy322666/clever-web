<?php

namespace Tests\Unit\Integrations;

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class FinderOAuthConfigTest extends TestCase
{
    #[DataProvider('redirectUris')]
    public function test_services_config_loads_finder_redirect_uri_without_bootstrapping_the_application(
        ?string $appUrl,
        ?string $override,
        string $expected,
    ): void {
        $previousContainer = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        $repositoryProperty = new ReflectionProperty(Env::class, 'repository');
        $previousRepository = $repositoryProperty->getValue();
        // Laravel's immutable repository cannot override variables exported by
        // CI. Isolate this config test without changing the process environment.
        $repository = RepositoryBuilder::createWithNoAdapters()->addAdapter(ArrayAdapter::class)->make();
        $values = ['APP_URL' => $appUrl, 'AMO_FINDER_REDIRECT_URI' => $override];

        foreach ($values as $key => $value) {
            if ($value !== null) $repository->set($key, $value);
        }

        try {
            $repositoryProperty->setValue(null, $repository);
            $services = require __DIR__.'/../../../config/services.php';

            $this->assertSame($expected, $services['amocrm']['widgets']['finder']['redirect_uri']);
            $this->assertFalse($services['amocrm']['widgets']['finder']['fallback_to_platform_credentials']);
        } finally {
            Container::setInstance($previousContainer);
            $repositoryProperty->setValue(null, $previousRepository);
        }
    }

    public static function redirectUris(): array
    {
        return [
            'localhost default' => [null, null, 'http://localhost/api/amocrm/install/finder'],
            'application URL' => ['https://platform.example.test/', null, 'https://platform.example.test/api/amocrm/install/finder'],
            'explicit override' => ['https://platform.example.test/', 'https://oauth.example.test/finder', 'https://oauth.example.test/finder'],
        ];
    }
}
