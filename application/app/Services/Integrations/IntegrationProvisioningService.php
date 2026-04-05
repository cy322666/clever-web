<?php

namespace App\Services\Integrations;

use App\Models\App;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IntegrationProvisioningService
{
    public function syncCatalogForAllUsers(): void
    {
        User::query()->select(['id'])->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $this->syncCatalogForUser($user);
            }
        });
    }

    public function syncCatalogForUser(User $user): void
    {
        foreach ($this->definitions() as $definition) {
            App::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'name' => $definition['name'],
                ],
                [
                    'resource_name' => $definition['resource'],
                ]
            );
        }
    }

    public function definitions(): Collection
    {
        return collect(config('integrations.definitions', []))
            ->map(function (array $definition, string $name): array {
                return [
                    'name' => $name,
                    'resource' => (string)($definition['resource'] ?? ''),
                    'public' => (bool)($definition['public'] ?? true),
                ];
            })
            ->filter(fn(array $definition): bool => $definition['resource'] !== '');
    }

    public function ensureSettingForApp(App $app): App
    {
        $resourceClass = (string)$app->resource_name;
        if (!class_exists($resourceClass) || !method_exists($resourceClass, 'getModel')) {
            return $app;
        }

        $settingModelClass = $resourceClass::getModel();
        if (!is_string($settingModelClass) || !class_exists($settingModelClass)) {
            return $app;
        }

        if ($app->setting_id) {
            $existing = $settingModelClass::query()->find($app->setting_id);
            if ($existing) {
                return $app;
            }
        }

        $user = $app->relationLoaded('user') ? $app->user : $app->user()->first();
        if (!$user instanceof User) {
            return $app;
        }

        /** @var Model $settingModel */
        $settingModel = new $settingModelClass();
        $table = $settingModel->getTable();

        $query = $settingModelClass::query()->where('user_id', $user->id);
        if (Schema::hasColumn($table, 'account_id') && $user->account?->id) {
            $query->where('account_id', $user->account->id);
        }

        $setting = $query->first();

        if (!$setting) {
            $payload = ['user_id' => $user->id];

            if (Schema::hasColumn($table, 'account_id')) {
                $payload['account_id'] = $user->account?->id;
            }

            $setting = $settingModelClass::query()->create($payload);
        }

        $app->setting_id = $setting->id;
        $app->save();

        return $app->refresh();
    }

    public function cleanupUnusedSettings(bool $apply): array
    {
        $stats = [
            'candidates' => 0,
            'removed' => 0,
            'orphan_candidates' => 0,
            'orphan_removed' => 0,
            'stale_app_candidates' => 0,
            'stale_app_removed' => 0,
        ];

        $apps = App::query()
            ->where('status', App::STATE_CREATED)
            ->whereNull('installed_at')
            ->whereNotNull('setting_id')
            ->get();

        foreach ($apps as $app) {
            $resourceClass = (string)$app->resource_name;
            if (!class_exists($resourceClass) || !method_exists($resourceClass, 'getModel')) {
                continue;
            }

            $modelClass = $resourceClass::getModel();
            if (!is_string($modelClass) || !class_exists($modelClass)) {
                continue;
            }

            $setting = $modelClass::query()->find($app->setting_id);
            if (!$setting || !$this->isSettingUntouched($setting)) {
                continue;
            }

            $stats['candidates']++;

            if ($apply) {
                DB::transaction(function () use ($app, $setting): void {
                    $app->setting_id = null;
                    $app->save();
                    $setting->delete();
                });
                $stats['removed']++;
            }
        }

        $validNames = $this->definitions()->pluck('name')->values()->all();

        if ($validNames !== []) {
            $staleApps = App::query()
                ->where('status', App::STATE_CREATED)
                ->whereNull('installed_at')
                ->whereNotIn('name', $validNames)
                ->get();

            foreach ($staleApps as $staleApp) {
                $setting = $this->resolveAppSetting($staleApp);
                $isUntouched = $setting ? $this->isSettingUntouched($setting) : true;

                if (!$isUntouched) {
                    continue;
                }

                $stats['stale_app_candidates']++;

                if ($apply) {
                    DB::transaction(function () use ($staleApp, $setting): void {
                        if ($setting) {
                            $setting->delete();
                        }
                        $staleApp->delete();
                    });
                    $stats['stale_app_removed']++;
                }
            }
        }

        foreach ($this->resourceClassesForCleanup() as $resourceClass) {
            if (!class_exists($resourceClass) || !method_exists($resourceClass, 'getModel')) {
                continue;
            }

            $modelClass = $resourceClass::getModel();
            if (!is_string($modelClass) || !class_exists($modelClass)) {
                continue;
            }

            /** @var Model $model */
            $model = new $modelClass();
            $table = $model->getTable();

            if (!Schema::hasColumn($table, 'user_id')) {
                continue;
            }

            $linked = App::query()
                ->where('resource_name', $resourceClass)
                ->whereNotNull('setting_id')
                ->pluck('setting_id');

            $query = $modelClass::query();
            if ($linked->isNotEmpty()) {
                $query->whereNotIn('id', $linked->all());
            }

            foreach ($query->get() as $orphan) {
                if (!$this->isSettingUntouched($orphan)) {
                    continue;
                }

                $stats['orphan_candidates']++;

                if ($apply) {
                    $orphan->delete();
                    $stats['orphan_removed']++;
                }
            }
        }

        return $stats;
    }

    private function isSettingUntouched(Model $setting): bool
    {
        $table = $setting->getTable();

        if (Schema::hasColumn($table, 'active') && (bool)$setting->getAttribute('active')) {
            return false;
        }

        if (
            Schema::hasColumn($table, 'created_at')
            && Schema::hasColumn($table, 'updated_at')
            && $setting->getAttribute('created_at')
            && $setting->getAttribute('updated_at')
            && (string)$setting->getAttribute('created_at') !== (string)$setting->getAttribute('updated_at')
        ) {
            return false;
        }

        return true;
    }

    private function resolveAppSetting(App $app): ?Model
    {
        if (!$app->setting_id) {
            return null;
        }

        $resourceClass = (string)$app->resource_name;
        if (!class_exists($resourceClass) || !method_exists($resourceClass, 'getModel')) {
            return null;
        }

        $settingModelClass = $resourceClass::getModel();
        if (!is_string($settingModelClass) || !class_exists($settingModelClass)) {
            return null;
        }

        return $settingModelClass::query()->find($app->setting_id);
    }

    private function resourceClassesForCleanup(): Collection
    {
        $definedResources = $this->definitions()
            ->pluck('resource')
            ->filter()
            ->values();

        $appsResources = App::query()
            ->whereNotNull('resource_name')
            ->distinct()
            ->pluck('resource_name');

        return $definedResources
            ->merge($appsResources)
            ->filter(fn($resource): bool => is_string($resource) && $resource !== '')
            ->unique()
            ->values();
    }
}
