<div class="clever-market-header">
    <div>
        <span class="clever-market-header__eyebrow">Каталог CleverCRM</span>
        <h2>Интеграции для {{ $crmLabel }}</h2>
        <p>Показываем только те решения, которые совместимы с выбранной CRM.</p>
    </div>

    <a href="{{ $settingsUrl }}" wire:navigate>
        <span>CRM</span>
        <strong>{{ $crmLabel }}</strong>
        <x-filament::icon icon="heroicon-m-chevron-right" />
    </a>
</div>

@once
    <style>
        .clever-market-header{display:flex;align-items:flex-end;justify-content:space-between;gap:1.25rem;width:100%;padding:1.5rem;border-radius:1.15rem;color:#fff;background:radial-gradient(circle at 12% 10%,rgba(255,255,255,.14),transparent 34%),linear-gradient(135deg,#171513 0%,#30251f 68%,#9a3d05 150%);box-shadow:0 18px 45px rgba(28,25,23,.12)}
        .clever-market-header__eyebrow{display:block;margin-bottom:.55rem;color:#fdba74;font-size:.68rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.clever-market-header h2{font-size:clamp(1.35rem,2.4vw,2rem);font-weight:780;letter-spacing:-.035em}.clever-market-header p{margin-top:.38rem;color:#d6d3d1;font-size:.82rem}
        .clever-market-header>a{display:flex;align-items:center;gap:.55rem;min-width:10.5rem;padding:.7rem .8rem;border:1px solid rgba(255,255,255,.16);border-radius:.8rem;background:rgba(255,255,255,.08);transition:background .16s ease,border-color .16s ease}.clever-market-header>a:hover{border-color:rgba(251,146,60,.7);background:rgba(255,255,255,.13)}.clever-market-header>a span{color:#a8a29e;font-size:.68rem;font-weight:750;text-transform:uppercase}.clever-market-header>a strong{margin-left:auto;font-size:.82rem}.clever-market-header>a svg{width:.95rem}
        .clever-market-card{transition:transform .16s ease,box-shadow .16s ease,border-color .16s ease}.clever-market-card:hover{transform:translateY(-2px);border-color:#fdba74!important;box-shadow:0 14px 32px rgba(28,25,23,.09)}.clever-market-card__description{min-height:3.4rem}
        @media(max-width:640px){.clever-market-header{align-items:stretch;flex-direction:column;padding:1.1rem}.clever-market-header>a{width:100%}.clever-market-card__description{min-height:auto}}
    </style>
@endonce
