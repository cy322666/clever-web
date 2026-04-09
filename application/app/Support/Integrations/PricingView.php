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
<div style="display:grid; gap:10px;">
    <div style="border:1px solid #E5E7EB; border-radius:12px; padding:12px 14px; background:#FFFFFF;">
        <div style="font-size:12px; color:#6B7280; line-height:16px;">1 месяц</div>
        <div style="font-size:22px; font-weight:700; line-height:28px; color:#111827; margin-top:2px;">{$month1}</div>
    </div>

    <div style="border:1px solid #CFE3FF; border-radius:12px; padding:12px 14px; background:#F6FAFF;">
        <div style="font-size:12px; color:#4B5563; line-height:16px;">6 месяцев</div>
        <div style="font-size:22px; font-weight:700; line-height:28px; color:#111827; margin-top:2px;">{$month6}</div>
        <div style="margin-top:6px; font-size:12px; color:#1D4ED8; line-height:16px;">2 483 ₽/мес · экономия 3 000 ₽</div>
    </div>

    <div style="border:1px solid #BFE7CF; border-radius:12px; padding:12px 14px; background:#F3FCF6;">
        <div style="font-size:12px; color:#4B5563; line-height:16px;">12 месяцев</div>
        <div style="font-size:22px; font-weight:700; line-height:28px; color:#111827; margin-top:2px;">{$month12}</div>
        <div style="margin-top:6px; font-size:12px; color:#166534; line-height:16px;">2 075 ₽/мес · экономия 7 000 ₽</div>
    </div>
</div>
HTML
        );
    }
}

