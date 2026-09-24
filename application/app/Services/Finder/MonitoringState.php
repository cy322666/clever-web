<?php

namespace App\Services\Finder;

use App\Models\App;
use App\Models\Integrations\Finder\Action;
use App\Models\Integrations\Finder\Conversation;
use App\Models\Integrations\Finder\Setting;
use App\Services\Billing\WidgetSubscriptionAccessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonitoringState
{
    public function setEnabled(Setting $setting, bool $enabled, ?array $options = null): Setting
    {
        return DB::transaction(function () use ($setting, $enabled, $options): Setting {
            $setting = Setting::query()->lockForUpdate()->findOrFail($setting->id);
            $widget = $setting->app()->lockForUpdate()->firstOrFail();

            if ($enabled) {
                if (! $setting->amoAccount()?->active) {
                    throw ValidationException::withMessages(['connection' => 'Сначала подключите amoCRM.']);
                }
                $options = app(SettingsValidator::class)->validate($options ?? $setting->options(), (int) $setting->user_id, true);
                $access = app(WidgetSubscriptionAccessService::class);
                if ((int) $widget->status === App::STATE_CREATED) {
                    $access->ensureTrialForWidget((int) $setting->user_id, 'finder', (int) config('integrations.default_trial_days', 7));
                    $widget->refresh();
                    if ((int) $widget->status !== App::STATE_ACTIVE) {
                        throw ValidationException::withMessages(['access' => 'Доступ к виджету «Контроль ответов» не активирован. Проверьте подписку.']);
                    }
                }
                if ((int) $widget->status === App::STATE_EXPIRES
                    || ($widget->expires_tariff_at && Carbon::parse($widget->expires_tariff_at)->endOfDay()->isPast())) {
                    throw ValidationException::withMessages(['access' => 'Срок доступа к виджету «Контроль ответов» закончился.']);
                }
                $widget->update(['status' => App::STATE_ACTIVE]);
                if (! $access->canUse((int) $setting->user_id, 'finder')) {
                    throw ValidationException::withMessages(['access' => 'Доступ к виджету «Контроль ответов» не активирован. Проверьте подписку.']);
                }
                $setting->settings = $options;
            } elseif ((int) $widget->status === App::STATE_ACTIVE) {
                $widget->update(['status' => App::STATE_INACTIVE]);
            }

            $setting->forceFill(['active' => $enabled, 'enabled' => $enabled])->save();
            if (! $enabled) {
                Conversation::query()->where('setting_id', $setting->id)->update(['pending_since' => null, 'next_check_at' => null]);
                Action::query()->where('setting_id', $setting->id)->where('status', 'pending')->update(['status' => 'cancelled']);
            }

            return $setting->refresh();
        });
    }
}
