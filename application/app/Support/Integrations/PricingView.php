<?php

namespace App\Support\Integrations;

use Illuminate\Support\HtmlString;

class PricingView
{
    public static function sidebarHtml(array $cost): HtmlString
    {
        $month1 = htmlspecialchars((string)($cost['1_month'] ?? '2 990 ₽'), ENT_QUOTES, 'UTF-8');
        $month6 = htmlspecialchars((string)($cost['6_month'] ?? '14 900 ₽'), ENT_QUOTES, 'UTF-8');
        $month12 = htmlspecialchars((string)($cost['12_month'] ?? '24 900 ₽'), ENT_QUOTES, 'UTF-8');

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
    <div class="integration-pricing__card">
        <div class="integration-pricing__period">1 месяц</div>
        <div class="integration-pricing__price">{$month1}</div>
    </div>

    <div class="integration-pricing__card integration-pricing__card--accent">
        <div class="integration-pricing__period">6 месяцев</div>
        <div class="integration-pricing__price">{$month6}</div>
        <div class="integration-pricing__note">2 483 ₽/мес · экономия 3 000 ₽</div>
    </div>

    <div class="integration-pricing__card integration-pricing__card--best">
        <div class="integration-pricing__period">12 месяцев</div>
        <div class="integration-pricing__price">{$month12}</div>
        <div class="integration-pricing__note">2 075 ₽/мес · экономия 7 000 ₽</div>
    </div>
</div>
HTML
        );
    }
}
