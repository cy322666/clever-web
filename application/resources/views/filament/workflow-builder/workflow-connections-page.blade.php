<x-filament-panels::page class="workflow-list-page workflow-connections-page">
    @include('filament.workflow-builder.workflow-overview-nav', ['active' => 'connections', 'folder' => $this->workflowGroupFilter, 'launch' => $this->workflowLaunchFilter])
    <section class="workflow-overview__content">
        @include('filament.workflow-builder.workflow-credentials', [
            'providers' => \App\Services\Workflows\WorkflowCredentials::providers(),
            'connections' => \App\Services\Workflows\WorkflowCredentials::options($this->workflowCredentialProvider),
            'workflowCredentialProvider' => $this->workflowCredentialProvider,
            'workflowCredentialMode' => $this->workflowCredentialMode,
            'workflowCredentialDeleteId' => $this->workflowCredentialDeleteId,
        ])
    </section>
</x-filament-panels::page>
