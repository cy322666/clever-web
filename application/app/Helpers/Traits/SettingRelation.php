<?php

namespace App\Helpers\Traits;

use App\Models\App;
use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @method hasOne(string $class, string $string, string $string1)
 * @method belongsTo(string $class, string $string, string $string1)
 */
trait SettingRelation
{
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'id','setting_id')
            ->where('user_id', $this->user_id)
            ->where('resource_name', static::$resource);
    }

    public function amoWidget(): string
    {
        /** @var App|null $app */
        $app = $this->relationLoaded('app') ? $this->getRelation('app') : $this->app()->first();

        if (!$app && isset($this->user_id)) {
            $app = App::query()
                ->where('user_id', $this->user_id)
                ->where('resource_name', static::$resource)
                ->first();
        }

        return Account::normalizeWidget($app?->name);
    }

    public function amoAccount(bool $createIfMissing = false, ?string $fallbackWidget = null): ?Account
    {
        /** @var User|null $user */
        $user = $this->relationLoaded('user') ? $this->getRelation('user') : $this->user()->first();

        if (!$user) {
            return null;
        }

        $widget = $this->amoWidget();

        if ($widget === Account::DEFAULT_WIDGET && $fallbackWidget) {
            $widget = Account::normalizeWidget($fallbackWidget);
        }

        return $user->resolveAmoAccountForWidget($widget, $createIfMissing);
    }
}
