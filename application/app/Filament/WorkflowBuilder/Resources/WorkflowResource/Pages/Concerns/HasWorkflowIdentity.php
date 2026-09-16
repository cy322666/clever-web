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
        $record = $this->identityRecord();
        if ($record) {
            $this->syncDefinition();
            $data = \App\Filament\WorkflowBuilder\Resources\WorkflowResource::forceInactiveWhenActivationInvalid(
                ['name' => $name, 'definition' => $this->definition, 'is_active' => (bool) $record->is_active], $record, notify: true);
            $record->forceFill($data)->save();
            $this->data['is_active'] = $record->is_active;
        }
        $this->data['name'] = $name;
    }

    public function setWorkflowTags(array $tags): void
    {
        Validator::make(['tags'=>$tags],['tags'=>'array|max:20','tags.*'=>'required|string|max:50'])->validate();
        $tags = array_values(array_unique(array_filter(array_map('trim',$tags))));
        $record = $this->identityRecord();
        if ($record) {
            $saved = $record->fresh()->definition ?? [];
            $record->forceFill(['definition'=>array_merge($saved,['tags'=>$tags])])->saveQuietly();
        }
        $this->definition['tags'] = $tags;
    }

    public function setWorkflowFolder(?string $folder): void
    {
        $folder = filled($folder) ? $folder : null;
        if ($folder !== null) WorkflowFolders::assertFolderExists($folder);
        if ($record = $this->identityRecord()) WorkflowFolders::move($record,$folder);
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
