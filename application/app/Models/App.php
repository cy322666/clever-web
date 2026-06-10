<?php

namespace App\Models;

use App\Models\Core\Account;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class App extends Model
{
    use HasFactory;

    const STATE_CREATED  = 0;
    const STATE_INACTIVE = 2;
    const STATE_ACTIVE   = 1;
    const STATE_EXPIRES  = 3;

    const STATE_CREATED_WORD  = '';
    const STATE_INACTIVE_WORD = 'Не активна';
    const STATE_ACTIVE_WORD   = 'Активна';
    const STATE_EXPIRES_WORD  = 'Закончилась';

    protected $fillable = [
        'resource_name',
        'setting_id',
        'user_id',
        'name',
        'expires_tariff_at',
        'status',
        'installed_at',
    ];

    public static function noPublicNames(): array
    {
        return self::definitionNames(false);
    }

    public static function definitionNames(?bool $public = null): array
    {
        return self::definitions($public)
            ->keys()
            ->values()
            ->all();
    }

    public static function definitions(?bool $public = null): Collection
    {
        return collect(config('integrations.definitions', []))
            ->filter(
                fn(array $definition, string $name): bool => self::definitionAvailable($name, $definition)
                    && (
                        $public === null
                        || (bool)($definition['public'] ?? true) === $public
                    )
            );
    }

    private static function definitionAvailable(string $name, array $definition): bool
    {
        $resource = (string)($definition['resource'] ?? '');

        if ($name === 'workflows' && !self::classAvailable(\Leek\FilamentWorkflows\WorkflowsPlugin::class)) {
            return false;
        }

        if (!self::classAvailable($resource)) {
            return false;
        }

        return true;
    }

    public static function classAvailable(?string $class): bool
    {
        if (!is_string($class) || $class === '') {
            return false;
        }

        if (class_exists($class, false)) {
            return true;
        }

        if (str_starts_with($class, 'App\\')) {
            $path = app_path(str_replace('\\', '/', substr($class, 4)) . '.php');

            if (!is_file($path)) {
                return false;
            }
        }

        try {
            return class_exists($class);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function getTooltipText(string $appName): string
    {
        $description = config("integrations.definitions.{$appName}.description");
        if (is_string($description) && $description !== '') {
            return $description;
        }

        return match ($appName) {
            'alfacrm' => 'Синхронизируйте клиентов и их посещения между amoCRM и АльфаСРМ',
            'distribution' => 'Гибко настройте распределение сделок между менеджерами',
            'getcourse' => 'Интегрируйте заявки и заказы из GetCourse с amoCRM',
            'bizon' => 'Настройте отправку регистрвцикй и посещений из Бизон 365',
            'tilda' => 'Отправляйте заявки с вашего сайта на Tilda в amoCRM без дублей',
            'yclients' => 'Синхронизируйте клиентов и их посещения между amoCRM и YClients',
            'amo-data' => 'Локальный сбор сделок и задач из amoCRM для аналитики и AI',
            'assistant' => 'AI ассистент руководителя по данным amoCRM: чат, сводки, риски и summary payload-ы для n8n',
            'call-transcription' => 'Транскрибация звонков с применением промпта, записью результата в поле или примечание и запуском Salesbot',
            'import-excel' => 'Импорт данных из Excel файлов в amoCRM с гибким маппингом полей для сделок, контактов и компаний',
            default => '',
        };
    }

    public static function getTitle(string $appName, ?string $resourceClass = null): string
    {
        $title = config("integrations.definitions.{$appName}.title");
        if (is_string($title) && $title !== '') {
            return $title;
        }

        if (
            is_string($resourceClass)
            && self::classAvailable($resourceClass)
            && method_exists($resourceClass, 'getRecordTitle')
        ) {
            return (string)$resourceClass::getRecordTitle();
        }

        return $appName;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            App::STATE_CREATED  => App::STATE_CREATED_WORD,
            App::STATE_INACTIVE => App::STATE_INACTIVE_WORD,
            App::STATE_ACTIVE   => App::STATE_ACTIVE_WORD,
            App::STATE_EXPIRES  => App::STATE_EXPIRES_WORD,
        };
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function getSettingModel()
    {
        return $this->resource_name::getModel()::query()->find($this->setting_id);
    }

    public static function isActiveWidget(Model $setting) : bool
    {
        return $setting->active;
    }
}
