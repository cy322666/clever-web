@if ($this instanceof \Filament\Actions\Contracts\HasActions && (! $this->hasActionsModalRendered))
    <div wire:partial="action-modals" x-data="filamentActionModals({ livewireId: @js($this->getId()) })" style="height: 0">
        @if (method_exists($this, 'isWorkflowInspectorAction'))
            @include('filament.workflow-builder.workflow-inspector')
        @endif
        @foreach ($this->getMountedActions() as $action)
            @if ((!method_exists($this, 'isWorkflowInspectorAction') || !$this->isWorkflowInspectorAction($action)) && ((!$loop->last) || $this->mountedActionShouldOpenModal()))
                {{ $action->toModalHtmlable() }}
            @endif
        @endforeach
    </div>
    @php($this->hasActionsModalRendered = true)
@endif
