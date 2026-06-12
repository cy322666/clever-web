<?php

namespace App\Models\Integrations\YClients;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponsibleMapping extends Model
{
    protected $table = 'yclients_responsible_mappings';

    protected $fillable = [
        'setting_id',
        'amo_user_id',
        'yc_user_keys',
        'active',
    ];

    protected $casts = [
        'yc_user_keys' => 'array',
        'active' => 'boolean',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function removeSelectedUsersFromOtherMappings(): void
    {
        $selectedKeys = $this->yc_user_keys ?? [];

        if ($selectedKeys === []) {
            return;
        }

        self::query()
            ->where('setting_id', $this->setting_id)
            ->whereKeyNot($this->getKey())
            ->get()
            ->each(function (self $mapping) use ($selectedKeys): void {
                $remainingKeys = array_values(array_diff($mapping->yc_user_keys ?? [], $selectedKeys));

                if ($remainingKeys !== ($mapping->yc_user_keys ?? [])) {
                    $mapping->update(['yc_user_keys' => $remainingKeys]);
                }
            });
    }
}
