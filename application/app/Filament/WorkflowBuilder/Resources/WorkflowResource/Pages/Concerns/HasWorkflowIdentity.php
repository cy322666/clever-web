<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Services\Workflows\WorkflowFolders;
use App\Services\Workflows\WorkflowTransfer;
use Illuminate\Support\Facades\Validator;

trait HasWorkflowIdentity
{
    public function renameWorkflow(string $name): void
    {
        $name = trim($name);
        Validator::make(['name'=>$name],['name'=>'required|string|max:255'])->validate();
        $this->identityRecord();
        $this->data['name'] = $name;
    }

    public function setWorkflowFolder(?string $folder): void
    {
        $folder = filled($folder) ? $folder : null;
        if ($folder !== null) WorkflowFolders::assertFolderExists($folder);
        $this->identityRecord();
        $this->data['group_name'] = $folder;
        if (property_exists($this, 'folder')) $this->folder = $folder;
    }

    public function exportCurrentWorkflow(array $layout = [])
    {
        $record = $this->identityRecord();
        $this->syncDefinition();
        $name = $this->data['name'] ?? $record?->name ?? 'Новый поток';
        $json = WorkflowTransfer::encode($name,$this->definition,$layout ?: ($this->definition['canvas_layout'] ?? []));
        return response()->streamDownload(static function () use ($json): void { echo $json; }, 'flow-'.($record?->id ?? 'draft').'.json', ['Content-Type'=>'application/json']);
    }

    private function identityRecord(): ?\Leek\FilamentWorkflows\Models\Workflow
    {
        abort_unless(auth()->id(), 403);
        $record = method_exists($this,'getRecord') ? $this->getRecord() : null;
        if ($record) abort_unless((int)$record->user_id === (int)auth()->id(), 403);
        return $record;
    }
}
