<?php

namespace App\Models\Workflows;

use App\Workflows\Triggers\GenericWebhookTrigger;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Auth;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Leek\FilamentWorkflows\Models\Workflow as BaseWorkflow;

class Workflow extends BaseWorkflow
{
    /**
     * @return array<int, string>
     */
    public function getFillable(): array
    {
        return array_values(array_unique([
            ...parent::getFillable(),
            'group_name',
        ]));
    }

    /**
     * @return array<string, string>
     */
    public static function groupOptions(): array
    {
        return static::query()
            ->whereNotNull('group_name')
            ->where('group_name', '<>', '')
            ->distinct()
            ->orderBy('group_name')
            ->pluck('group_name', 'group_name')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return array_replace(parent::casts(), [
            'failure_strategy' => 'string',
        ]);
    }

    protected static function getCurrentTenantId(): int|string|null
    {
        return Auth::id();
    }

    protected function syncTriggerMetadata(): void
    {
        parent::syncTriggerMetadata();

        if (data_get($this->definition, 'trigger.type') !== GenericWebhookTrigger::type()) {
            return;
        }

        $this->trigger_type = TriggerType::WEBHOOK;
        $this->trigger_event = null;
        $this->trigger_model_type = null;
    }

    protected function groupName(): Attribute
    {
        return Attribute::make(
            set: static function (?string $value): ?string {
                $value = trim((string)$value);

                return $value !== '' ? $value : null;
            },
        );
    }
}
