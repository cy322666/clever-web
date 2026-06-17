<?php

namespace App\Filament\Resources\Billing\Widgets;

use App\Models\Billing\SubscriptionInvoiceRequest;
use App\Models\Billing\SubscriptionPlan;
use App\Models\User;
use App\Services\Billing\WidgetSubscriptionAccessService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class BlockedSubscriptionsNotice extends Widget
{
    protected string $view = 'filament.resources.billing.widgets.blocked-subscriptions-notice';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->check() && !(bool)auth()->user()?->is_root && static::hasBlockedWidgets();
    }

    public static function hasBlockedWidgets(): bool
    {
        return static::blockedWidgets() !== [];
    }

    public function requestInvoice(string $widget): void
    {
        $user = Auth::user();

        if (!$user instanceof User) {
            return;
        }

        $widget = trim($widget);

        if ($widget === '') {
            return;
        }

        $existingRequest = SubscriptionInvoiceRequest::query()
            ->where('user_id', $user->id)
            ->where('widget', $widget)
            ->whereIn('status', [
                SubscriptionInvoiceRequest::STATUS_NEW,
                SubscriptionInvoiceRequest::STATUS_IN_PROGRESS,
                SubscriptionInvoiceRequest::STATUS_INVOICE_SENT,
            ])
            ->latest('id')
            ->first();

        if ($existingRequest) {
            Notification::make()
                ->title('Заявка уже есть')
                ->body('Мы уже видим запрос на продление этого виджета.')
                ->warning()
                ->send();

            return;
        }

        SubscriptionInvoiceRequest::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => SubscriptionPlan::query()
                ->active()
                ->where('widget', $widget)
                ->orderBy('period_days')
                ->value('id'),
            'widget' => $widget,
            'status' => SubscriptionInvoiceRequest::STATUS_NEW,
            'contact_name' => $user->name,
            'contact_email' => $user->email,
            'comment' => 'Запрос на продление доступа из экрана блокировки.',
        ]);

        Notification::make()
            ->title('Заявка отправлена')
            ->body('Поддержка увидит запрос и свяжется с вами.')
            ->success()
            ->send();
    }

    protected function getViewData(): array
    {
        return [
            'blockedWidgets' => static::blockedWidgets(),
        ];
    }

    private static function blockedWidgets(): array
    {
        $user = Auth::user();

        if (!$user instanceof User) {
            return [];
        }

        return collect(app(WidgetSubscriptionAccessService::class)->blockedWidgetsFor($user))
            ->map(function (array $widget): array {
                $widget['ends_at_label'] = static::dateLabel($widget['ends_at'] ?? null);
                $widget['grace_until_label'] = static::dateLabel($widget['grace_until'] ?? null);

                return $widget;
            })
            ->all();
    }

    private static function dateLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
