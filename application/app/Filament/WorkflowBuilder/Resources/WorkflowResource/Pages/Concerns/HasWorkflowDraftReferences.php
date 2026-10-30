<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

trait HasWorkflowDraftReferences
{
    // Plain arrays, never Eloquent collections: model hydration would query SQL.
    #[\Livewire\Attributes\Locked]
    public array $workflowReferenceRows = [];

    public function bootHasWorkflowDraftReferences(): void
    {
        $draft = new \stdClass;
        $draft->rows =& $this->workflowReferenceRows;
        app()->instance('workflow.editor.reference-draft', $draft);
    }

    public function callHasWorkflowDraftReferences(string $methodName): void
    {
        if (method_exists($this, 'refreshWorkflowRecordForRuntime') && (
            in_array($methodName, ['runWorkflowCanvasNode', 'runEditingWorkflowNode', 'runWorkflowTest', 'startWorkflowDebug', 'nextWorkflowDebugStep', 'duplicateCurrentWorkflow'], true)
            || ($methodName === 'callMountedAction' && ($this->mountedActions[0]['name'] ?? '') === 'deleteWorkflow')
        )) {
            $this->refreshWorkflowRecordForRuntime();
            // The execution path must not validate API filters against a stale
            // editor dictionary. Preview jobs run in their own fresh process too.
            app()->forgetInstance('workflow.editor.reference-draft');
        }
        if (in_array($methodName, ['refreshWorkflowReference', 'refreshEditingNodeReferences'], true)) {
            $this->workflowReferenceRows = [];
        }
        if (method_exists($this,'workflowInspectorCacheKey') && (
            in_array($methodName,['refreshWorkflowReference','refreshEditingNodeReferences','saveWorkflowCredential','deleteWorkflowCredential'],true)
            || ($methodName==='callMountedAction' && ($this->mountedActions[array_key_last($this->mountedActions)]['name'] ?? '')==='createOption')
        )) {
            \Illuminate\Support\Facades\Cache::forget($this->workflowInspectorCacheKey());
            $this->localInspectorBootstrap=null;
        }
    }

    public function workflowEditorFolderOptions(): array
    {
        $key = 'folders:'.auth()->id();
        return $this->workflowReferenceRows[$key] ??= \App\Services\Workflows\WorkflowFolders::options();
    }
}
