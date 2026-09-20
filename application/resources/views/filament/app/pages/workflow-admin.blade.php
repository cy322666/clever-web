@php
    $summary = $this->summary();
    $accounts = $this->accountRows();
    $workflows = $this->recentWorkflows();
@endphp

<x-filament-panels::page>
    <div class="workflow-admin-page">
        <section class="workflow-admin-stats" aria-label="Общая статистика потоков">
            <article><strong>{{ $summary['accounts'] }}</strong><span>аккаунтов</span></article>
            <article><strong>{{ $summary['active'] }} / {{ $summary['workflows'] }}</strong><span>активных сценариев</span></article>
            <article><strong>{{ $summary['runs'] }}</strong><span>запусков за 24 ч</span></article>
            <article @class(['is-danger' => $summary['failed'] > 0])><strong>{{ $summary['failed'] }}</strong><span>ошибок за 24 ч</span></article>
            <article @class(['is-warning' => $summary['queued'] > 0])><strong>{{ $summary['queued'] }}</strong><span>в очереди</span></article>
            <article><strong>{{ $summary['credentials'] }}</strong><span>подключений</span></article>
        </section>

        <section class="workflow-admin-section">
            <header>
                <div><h2>Аккаунты</h2><p>Короткое состояние автоматизаций по владельцам.</p></div>
                <label class="workflow-admin-search">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" />
                    <input wire:model.live.debounce.250ms="search" type="search" placeholder="Email или домен" aria-label="Поиск аккаунта">
                </label>
            </header>
            <div class="workflow-admin-table-wrap">
                <table class="workflow-admin-table">
                    <thead><tr><th>Аккаунт</th><th>Сценарии</th><th>24 часа</th><th>Очередь</th><th>Подключения</th></tr></thead>
                    <tbody>
                    @forelse($accounts as $account)
                        @php
                            $amo = $account->accounts->first();
                        @endphp
                        <tr>
                            <td><strong>{{ $amo?->subdomain ?: $account->name }}</strong><small>{{ $account->email }}</small></td>
                            <td><strong>{{ $account->active_workflows_count }} / {{ $account->workflows_count }}</strong><small>активно</small></td>
                            <td><strong>{{ $account->runs_day_count }}</strong><small @class(['is-danger-text' => $account->failed_day_count > 0])>{{ $account->failed_day_count }} ошибок</small></td>
                            <td><span @class(['workflow-admin-badge', 'is-visible' => $account->queued_runs_count > 0])>{{ $account->queued_runs_count }}</span></td>
                            <td><strong>{{ $account->workflow_credentials_count }}</strong></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="workflow-admin-empty">Аккаунты не найдены</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="workflow-admin-section">
            <header><div><h2>Недавно изменённые сценарии</h2><p>Нажмите на сценарий, чтобы открыть его в контексте владельца.</p></div></header>
            <div class="workflow-admin-workflows">
                @forelse($workflows as $workflow)
                    @php
                        $owner = $workflow->owner;
                        $domain = $owner?->accounts?->first()?->subdomain;
                        $runStatus = $workflow->latestRun?->status;
                    @endphp
                    <a href="{{ $this->workflowUrl($workflow) }}">
                        <span class="workflow-admin-workflow-state {{ $workflow->is_active ? 'is-active' : '' }}"></span>
                        <span class="workflow-admin-workflow-name"><strong>{{ $workflow->name }}</strong><small>{{ $domain ?: $owner?->email }}</small></span>
                        @if($workflow->queued_runs_count > 0)<span class="workflow-admin-queue">В очереди: {{ $workflow->queued_runs_count }}</span>@endif
                        <span class="workflow-admin-run">{{ $runStatus?->getLabel() ?: 'Не запускался' }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-right" />
                    </a>
                @empty
                    <p class="workflow-admin-empty">Сценариев пока нет</p>
                @endforelse
            </div>
        </section>
    </div>

    @once
        <style>
            .workflow-admin-page{display:grid;gap:1.25rem}.workflow-admin-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:.75rem}.workflow-admin-stats article,.workflow-admin-section{border:1px solid #e4e4e7;border-radius:.85rem;background:#fff}.workflow-admin-stats article{display:grid;gap:.1rem;padding:.9rem 1rem}.workflow-admin-stats strong{font-size:1.3rem}.workflow-admin-stats span,.workflow-admin-section header p,.workflow-admin-table small,.workflow-admin-workflow-name small{color:#71717a;font-size:.76rem}.workflow-admin-stats .is-danger strong,.is-danger-text{color:#ef4444}.workflow-admin-stats .is-warning strong{color:#f59e0b}.workflow-admin-section{overflow:hidden}.workflow-admin-section>header{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.1rem;border-bottom:1px solid #e4e4e7}.workflow-admin-section h2{font-size:1rem;font-weight:750}.workflow-admin-search{display:flex;align-items:center;gap:.5rem;width:17rem;padding:0 .75rem;border:1px solid #d4d4d8;border-radius:.65rem}.workflow-admin-search svg{width:1rem;color:#71717a}.workflow-admin-search input{width:100%;height:2.4rem;background:transparent;outline:none;font-size:.84rem}.workflow-admin-table-wrap{overflow:auto}.workflow-admin-table{width:100%;border-collapse:collapse}.workflow-admin-table th,.workflow-admin-table td{padding:.75rem 1.1rem;text-align:left;border-bottom:1px solid #f0f0f1;font-size:.84rem}.workflow-admin-table th{color:#71717a;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em}.workflow-admin-table td strong,.workflow-admin-table td small,.workflow-admin-workflow-name{display:block}.workflow-admin-badge{display:none}.workflow-admin-badge.is-visible,.workflow-admin-queue{display:inline-flex;padding:.18rem .45rem;border-radius:999px;background:rgba(245,158,11,.13);color:#d97706;font-size:.72rem;font-weight:700}.workflow-admin-workflows{display:grid}.workflow-admin-workflows>a{display:flex;align-items:center;gap:.75rem;min-height:3.7rem;padding:.65rem 1rem;border-bottom:1px solid #f0f0f1;color:inherit}.workflow-admin-workflows>a:hover{background:#fafafa}.workflow-admin-workflows>a:last-child,.workflow-admin-table tr:last-child td{border-bottom:0}.workflow-admin-workflow-state{width:.55rem;height:.55rem;border-radius:50%;background:#a1a1aa}.workflow-admin-workflow-state.is-active{background:#22c55e}.workflow-admin-workflow-name{min-width:0;flex:1}.workflow-admin-workflow-name strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.87rem}.workflow-admin-run{color:#71717a;font-size:.78rem;white-space:nowrap}.workflow-admin-workflows>a>svg{width:1rem;color:#a1a1aa}.workflow-admin-empty{padding:1.25rem!important;text-align:center!important;color:#71717a}.dark .workflow-admin-stats article,.dark .workflow-admin-section{border-color:#3f3f46;background:#27272a}.dark .workflow-admin-section>header,.dark .workflow-admin-table th,.dark .workflow-admin-table td,.dark .workflow-admin-workflows>a{border-color:#3f3f46}.dark .workflow-admin-workflows>a:hover{background:#303036}.dark .workflow-admin-search{border-color:#52525b}@media(max-width:1100px){.workflow-admin-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:700px){.workflow-admin-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.workflow-admin-section>header{align-items:stretch;flex-direction:column}.workflow-admin-search{width:100%}.workflow-admin-table th:nth-child(3),.workflow-admin-table td:nth-child(3),.workflow-admin-table th:nth-child(5),.workflow-admin-table td:nth-child(5){display:none}}
        </style>
    @endonce
</x-filament-panels::page>
