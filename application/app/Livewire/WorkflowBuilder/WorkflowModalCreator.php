<?php

namespace App\Livewire\WorkflowBuilder;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\CreateWorkflow;
use Filament\Resources\Events\RecordCreated;
use Filament\Resources\Events\RecordSaved;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Event;
use Throwable;

class WorkflowModalCreator extends CreateWorkflow
{
    protected string $view = 'livewire.workflow-builder.workflow-modal-creator';

    public function create(bool $another = false): void
    {
        if ($this->isCreating) {
            return;
        }

        $this->isCreating = true;
        $this->authorizeAccess();

        try {
            $this->beginDatabaseTransaction();
            $this->callHook('beforeValidate');

            $data = $this->form->getState();

            $this->callHook('afterValidate');

            $data = $this->mutateFormDataBeforeCreate($data);

            $this->callHook('beforeCreate');

            $this->record = $this->handleRecordCreation($data);

            $this->form->model($this->getRecord())->saveRelationships();

            $this->callHook('afterCreate');
            Event::dispatch(RecordCreated::class, ['record' => $this->record, 'data' => $data, 'page' => $this]);
            Event::dispatch(RecordSaved::class, ['record' => $this->record, 'data' => $data, 'page' => $this]);
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction()
                ? $this->rollBackDatabaseTransaction()
                : $this->commitDatabaseTransaction();

            $this->isCreating = false;

            return;
        } catch (Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            $this->isCreating = false;

            throw $exception;
        }

        $this->commitDatabaseTransaction();
        $this->rememberData();
        $this->getCreatedNotification()?->send();

        $this->isCreating = false;
        $this->dispatch('workflow-modal-created');
    }
}
