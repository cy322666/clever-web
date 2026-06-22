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
