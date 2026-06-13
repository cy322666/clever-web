<?php

namespace App\Models\Workflows;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Auth;
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
