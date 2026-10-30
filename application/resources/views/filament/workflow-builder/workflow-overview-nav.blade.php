<nav class="workflow-overview-nav" aria-label="Автоматизация аккаунта">
    @php($sidebarParams = array_filter(['folder' => $folder ?? null, 'launch' => $launch ?? null], fn ($value) => $value !== null))
    <a href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('index', $sidebarParams) }}" @if($active === 'workflows') aria-current="page" @endif>Сценарии</a>
    <a href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowRunResource::getUrl('index', $sidebarParams) }}" @if($active === 'history') aria-current="page" @endif>История</a>
    <a href="{{ \App\Filament\WorkflowBuilder\Resources\WorkflowResource::getUrl('connections', $sidebarParams) }}" @if($active === 'connections') aria-current="page" @endif>Подключения</a>
</nav>
