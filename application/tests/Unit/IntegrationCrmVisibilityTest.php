<?php

namespace Tests\Unit;

use App\Models\App;
use App\Services\Integrations\IntegrationProvisioningService;
use App\Support\Crm\CrmProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntegrationCrmVisibilityTest extends TestCase
{
    public function test_every_integration_declares_supported_crm_providers(): void
    {
        $allowed = [CrmProvider::AMOCRM, CrmProvider::KOMMO];

        foreach (config('integrations.definitions', []) as $name => $definition) {
            $providers = $definition['crm_providers'] ?? null;

            $this->assertIsArray($providers, "{$name} must declare crm_providers.");
            $this->assertNotEmpty($providers, "{$name} must support at least one CRM.");
            $this->assertSame([], array_values(array_diff($providers, $allowed)), "{$name} contains an unknown CRM.");
        }
    }

    public function test_catalog_filters_integrations_by_crm_provider(): void
    {
        config()->set('integrations.definitions', $this->mixedCrmDefinitions());

        $this->assertSame(['amo-only', 'shared'], App::definitionNamesForCrmProvider(CrmProvider::AMOCRM, true));
        $this->assertSame(['kommo-only', 'shared'], App::definitionNamesForCrmProvider(CrmProvider::KOMMO, true));
    }

    public function test_bulk_catalog_sync_creates_only_compatible_integrations(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'integrations.definitions' => $this->mixedCrmDefinitions(),
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('crm_provider', 20)->nullable();
            $table->timestamps();
        });
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('name');
            $table->string('resource_name')->nullable();
            $table->integer('status')->default(App::STATE_CREATED);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        DB::table('users')->insert([
            ['id' => 1, 'crm_provider' => CrmProvider::AMOCRM],
            ['id' => 2, 'crm_provider' => CrmProvider::KOMMO],
        ]);

        app(IntegrationProvisioningService::class)->syncCatalogForAllUsers();

        $this->assertSame(
            [[1, 'amo-only'], [1, 'shared'], [2, 'kommo-only'], [2, 'shared']],
            DB::table('apps')->orderBy('user_id')->orderBy('name')->get()
                ->map(fn ($app): array => [(int) $app->user_id, $app->name])
                ->all(),
        );
    }

    private function mixedCrmDefinitions(): array
    {
        return [
            'amo-only' => [
                'resource' => \App\Filament\Resources\Core\UserResource::class,
                'public' => true,
                'crm_providers' => [CrmProvider::AMOCRM],
            ],
            'kommo-only' => [
                'resource' => \App\Filament\Resources\Core\UserResource::class,
                'public' => true,
                'crm_providers' => [CrmProvider::KOMMO],
            ],
            'shared' => [
                'resource' => \App\Filament\Resources\Core\UserResource::class,
                'public' => true,
                'crm_providers' => [CrmProvider::AMOCRM, CrmProvider::KOMMO],
            ],
        ];
    }
}
