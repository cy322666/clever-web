<nav class="workflow-overview-nav" aria-label="Автоматизация аккаунта">
    <a href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('index') }}" @if($active === 'workflows') aria-current="page" @endif>Сценарии</a>
    <a href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowRunResource::getUrl('index') }}" @if($active === 'history') aria-current="page" @endif>История</a>
</nav>
