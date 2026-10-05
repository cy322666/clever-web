<?php

namespace App\Support\Integrations;

use Illuminate\Support\HtmlString;

class PricingView
{
    public static function sidebarHtml(array $cost, bool $showSavings = true, ?int $accountUserLimit = null): HtmlString
    {
        $cost += [
            '1_month' => '2 990 ₽',
            '6_month' => '14 900 ₽',
            '12_month' => '24 900 ₽',
        ];

        $plans = [
            '1_month' => ['label' => '1 месяц', 'class' => ''],
            '3_month' => ['label' => '3 месяца', 'class' => ''],
            '6_month' => [
                'label' => '6 месяцев',
                'class' => ' integration-pricing__card--accent',
                'note' => $showSavings ? '2 483 ₽/мес · экономия 3 000 ₽' : null,
            ],
            '12_month' => [
                'label' => '12 месяцев',
                'class' => isset($cost['24_month']) ? '' : ' integration-pricing__card--best',
                'note' => $showSavings ? '2 075 ₽/мес · экономия 7 000 ₽' : null,
            ],
            '24_month' => ['label' => '24 месяца', 'class' => ' integration-pricing__card--best'],
        ];
        $pricingCards = '';

        foreach ($plans as $key => $plan) {
            if (! array_key_exists($key, $cost)) {
                continue;
            }

            $price = htmlspecialchars((string) $cost[$key], ENT_QUOTES, 'UTF-8');
            $note = isset($plan['note'])
                ? '<div class="integration-pricing__note">'.$plan['note'].'</div>'
                : '';
            $pricingCards .= '<div class="integration-pricing__card'.$plan['class'].'">'
                .'<div class="integration-pricing__period">'.$plan['label'].'</div>'
                .'<div class="integration-pricing__price">'.$price.'</div>'
                .$note
                .'</div>';
        }

        $accountPricing = '';
        $perUserPricing = '';

        if ($accountUserLimit !== null) {
            $accountPricing = '<div><strong>До '.$accountUserLimit.' пользователей включительно</strong><div class="integration-pricing__period">Стоимость за аккаунт</div></div>';

            if (isset($cost['1_month_per_user'])) {
                $perUser = htmlspecialchars((string) $cost['1_month_per_user'], ENT_QUOTES, 'UTF-8');
                $perUserPricing = '<div class="integration-pricing__card"><div><strong>Более '.$accountUserLimit.' пользователей</strong></div><div class="integration-pricing__price">'.$perUser.' в месяц</div><div class="integration-pricing__period">За каждого пользователя аккаунта</div></div>';
            }
        }

        return new HtmlString(
            <<<HTML
<style>
    .integration-pricing {
        display: grid;
        gap: 0.625rem;
    }

    .integration-pricing__card {
        border: 1px solid rgb(216 208 197 / 0.85);
        border-radius: 0.75rem;
        background: rgb(255 255 255 / 0.88);
        padding: 0.75rem 0.875rem;
        box-shadow: 0 3px 10px rgb(15 15 15 / 0.04);
    }

    .integration-pricing__card--accent {
        border-color: rgb(255 106 0 / 0.32);
        background: rgb(255 241 229 / 0.48);
    }

    .integration-pricing__card--best {
        border-color: rgb(47 159 103 / 0.34);
        background: rgb(233 247 239 / 0.54);
    }

    .integration-pricing__period {
        color: rgb(107 114 128);
        font-size: 0.75rem;
        line-height: 1rem;
    }

    .integration-pricing__price {
        margin-top: 0.125rem;
        color: rgb(17 24 39);
        font-size: 1.375rem;
        font-weight: 700;
        line-height: 1.75rem;
    }

    .integration-pricing__note {
        margin-top: 0.375rem;
        color: rgb(194 78 0);
        font-size: 0.75rem;
        line-height: 1rem;
    }

    .integration-pricing__card--best .integration-pricing__note {
        color: rgb(31 122 77);
    }

    .dark .integration-pricing__card {
        border-color: rgb(73 60 48 / 0.82);
        background: rgb(24 22 20 / 0.9);
        box-shadow: 0 8px 18px rgb(0 0 0 / 0.18);
    }

    .dark .integration-pricing__card--accent {
        border-color: rgb(255 106 0 / 0.44);
        background: rgb(69 26 3 / 0.28);
    }

    .dark .integration-pricing__card--best {
        border-color: rgb(47 159 103 / 0.42);
        background: rgb(12 42 28 / 0.36);
    }

    .dark .integration-pricing__period {
        color: rgb(168 162 158);
    }

    .dark .integration-pricing__price {
        color: rgb(245 245 244);
    }

    .dark .integration-pricing__note {
        color: rgb(255 190 128);
    }

    .dark .integration-pricing__card--best .integration-pricing__note {
        color: rgb(134 239 172);
    }
</style>

<div class="integration-pricing">
    {$accountPricing}
    {$pricingCards}
    {$perUserPricing}
</div>
HTML
        );
    }
}
