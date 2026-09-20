@if($this->workflowAdminAccess)
    <div class="workflow-admin-context-banner">
        <span><strong>Админский доступ</strong> · {{ $this->workflowAdminOwnerLabel }}</span>
        <a href="{{ \App\Filament\App\Pages\WorkflowAdmin::getUrl() }}">К обзору</a>
    </div>

    @once
        <style>
            .workflow-admin-context-banner{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.65rem .9rem;border:1px solid rgba(249,115,82,.35);border-radius:.75rem;background:rgba(249,115,82,.08);font-size:.82rem;color:inherit}
            .workflow-admin-context-banner a{font-weight:700;color:#f97352;white-space:nowrap}
        </style>
    @endonce
@endif
