<?php

namespace App\Models\Integrations\Finder;

use App\Filament\Resources\Integrations\Finder\FinderResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\Core\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use SettingRelation;

    protected $table = 'finder_settings';

    protected $fillable = ['user_id', 'account_id', 'active', 'enabled', 'settings'];

    protected $casts = ['active' => 'boolean', 'enabled' => 'boolean', 'settings' => 'array', 'connected_at' => 'datetime', 'last_webhook_at' => 'datetime'];

    public static string $resource = FinderResource::class;

    public static array $cost = [
        '1_month' => '0 руб',
        '6_month' => '0 руб',
        '12_month' => '0 руб',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function amoAccount(bool $createIfMissing = false, ?string $fallbackWidget = null): ?Account
    {
        // Finder uses the platform connection, without its own OAuth installation.
        return $this->user?->resolveAmoAccountForWidget(Account::DEFAULT_WIDGET, false);
    }

    public function options(): array
    {
        return array_replace([
            'working_time' => false,
            'timezone' => 'Europe/Moscow',
            'schedule' => [['days' => [1, 2, 3, 4, 5], 'from' => '09:00', 'to' => '20:00']],
            'hours' => 0, 'minutes' => 5, 'max_attempts' => 3,
            'run_workflow' => false, 'workflow_id' => null,
            'create_task' => false, 'responsible_user_id' => null,
            'task_type_id' => 1, 'task_text' => 'Ответить клиенту', 'task_due_minutes' => 15,
            'run_reply_workflow' => false, 'reply_workflow_id' => null,
        ], $this->settings ?? []);
    }

    public function intervalSeconds(): int
    {
        $options = $this->options();

        return max(60, ((int) $options['hours'] * 60 + (int) $options['minutes']) * 60);
    }
}
