<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Services\Workflows\WorkflowCredentials;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

trait HasWorkflowCredentials
{
    public string $workflowCredentialProvider = 'telegram';

    public string $workflowCredentialMode = 'list';

    public ?int $workflowCredentialId = null;

    public ?int $workflowCredentialDeleteId = null;

    public string $workflowCredentialToken = '';

    public function workflowCredentialsAction(): Action
    {
        return Action::make('workflowCredentials')
            ->label('Подключения')
            ->modalHeading('Подключения')
            ->modalDescription('Подключите сервис один раз и выбирайте его в нужных нодах.')
            ->modalWidth('2xl')
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->mountUsing(fn () => $this->resetWorkflowCredentialManager())
            ->modalContent(fn () => view('filament.workflow-builder.workflow-credentials', [
                'providers' => WorkflowCredentials::providers(),
                'connections' => WorkflowCredentials::options($this->workflowCredentialProvider),
                'workflowCredentialProvider' => $this->workflowCredentialProvider,
                'workflowCredentialMode' => $this->workflowCredentialMode,
                'workflowCredentialDeleteId' => $this->workflowCredentialDeleteId,
            ]))
            ->action(fn (): null => null);
    }

    public function resetWorkflowCredentialManager(): void
    {
        $this->workflowCredentialProvider = array_key_first(WorkflowCredentials::providers()) ?? '';
        $this->resetWorkflowCredentialForm();
    }

    public function updatedWorkflowCredentialProvider(string $provider): void
    {
        if (! array_key_exists($provider, WorkflowCredentials::providers())) {
            $this->workflowCredentialProvider = array_key_first(WorkflowCredentials::providers()) ?? '';
        }

        $this->resetWorkflowCredentialForm();
    }

    public function beginCreateWorkflowCredential(): void
    {
        $this->resetWorkflowCredentialForm();
        $this->workflowCredentialMode = 'create';
    }

    public function beginEditWorkflowCredential(int $id): void
    {
        $this->guardWorkflowCredential($id);
        $this->resetWorkflowCredentialForm();
        $this->workflowCredentialId = $id;
        $this->workflowCredentialMode = 'edit';
    }

    public function cancelWorkflowCredentialForm(): void
    {
        $this->resetWorkflowCredentialForm();
    }

    public function requestDeleteWorkflowCredential(int $id): void
    {
        $this->guardWorkflowCredential($id);
        $this->workflowCredentialDeleteId = $id;
    }

    public function cancelDeleteWorkflowCredential(): void
    {
        $this->workflowCredentialDeleteId = null;
    }

    public function deleteWorkflowCredential(int $id): void
    {
        $this->guardWorkflowCredential($id);
        WorkflowCredentials::delete($this->workflowCredentialProvider, $id);
        $this->resetWorkflowCredentialForm();

        Notification::make()->success()->title('Подключение удалено')->send();
    }

    public function saveWorkflowCredential(): void
    {
        $editing = $this->workflowCredentialMode === 'edit';
        if ($editing && $this->workflowCredentialId) {
            $this->guardWorkflowCredential($this->workflowCredentialId);
        }

        try {
            WorkflowCredentials::save([
                'provider' => $this->workflowCredentialProvider,
                'credential_id' => $editing ? $this->workflowCredentialId : null,
                'credentials' => ['token' => $this->workflowCredentialToken],
            ]);
        } catch (ValidationException $error) {
            $message = $error->errors()['credentials.token'][0]
                ?? $error->errors()['provider'][0]
                ?? 'Не удалось сохранить подключение.';
            $this->addError('workflowCredentialToken', $message);

            return;
        }

        $this->resetWorkflowCredentialForm();
        Notification::make()->success()->title('Подключение сохранено')->send();
    }

    private function guardWorkflowCredential(int $id): void
    {
        abort_unless(array_key_exists($id, WorkflowCredentials::options($this->workflowCredentialProvider)), 404);
    }

    private function resetWorkflowCredentialForm(): void
    {
        $this->workflowCredentialMode = 'list';
        $this->workflowCredentialId = null;
        $this->workflowCredentialDeleteId = null;
        $this->workflowCredentialToken = '';
        $this->resetErrorBag('workflowCredentialToken');
    }
}
