<div class="integration-pricing">
    <div>
        <div><strong>До 5 пользователей включительно</strong></div>
        <div class="integration-pricing__period">Стоимость за аккаунт</div>
    </div>

    {{ \App\Support\Integrations\PricingView::sidebarHtml($cost, showSavings: false) }}

    <div class="integration-pricing__card">
        <div><strong>Более 5 пользователей</strong></div>
        <div class="integration-pricing__price">{{ $cost['1_month_per_user'] }} в месяц</div>
        <div class="integration-pricing__period">За каждого пользователя аккаунта</div>
    </div>
</div>
