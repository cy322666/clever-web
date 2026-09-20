<?php

namespace App\Filament\App\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowCredentialResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource;
use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowCredential;
use App\Models\Workflows\WorkflowRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Leek\FilamentWorkflows\Enums\RunStatus;
use Livewire\Attributes\Url;

class WorkflowAdmin extends Page
{
    protected static ?string $title = 'Администрирование потоков';

    protected static ?string $navigationLabel = 'Админ потоков';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string $routePath = 'workflow-admin';

    protected string $view = 'filament.app.pages.workflow-admin';

    #[Url(as: 'q')]
    public string $search = '';

    public static function canAccess(): bool
    {
        return auth()->check() && (bool) auth()->user()?->is_root;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('workflows')->label('Все сценарии')->icon('heroicon-o-squares-2x2')->color('gray')->url(WorkflowResource::getUrl()),
            Action::make('runs')->label('Все исполнения')->icon('heroicon-o-play-circle')->color('gray')->url(WorkflowRunResource::getUrl()),
            Action::make('credentials')->label('Все подключения')->icon('heroicon-o-key')->color('gray')->url(WorkflowCredentialResource::getUrl()),
        ];
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $since = now()->subDay();
        $runs = WorkflowRun::withoutGlobalScope('tenant');

        return [
            'accounts' => (int) Workflow::withoutGlobalScope('tenant')->distinct()->count('user_id'),
            'workflows' => (int) Workflow::withoutGlobalScope('tenant')->count(),
            'active' => (int) Workflow::withoutGlobalScope('tenant')->where('is_active', true)->count(),
            'runs' => (int) (clone $runs)->where('created_at', '>=', $since)->count(),
            'failed' => (int) (clone $runs)->where('created_at', '>=', $since)->where('status', RunStatus::FAILED->value)->count(),
            'queued' => (int) (clone $runs)->where('status', RunStatus::PENDING->value)->count(),
            'credentials' => (int) WorkflowCredential::query()->count(),
        ];
    }

    /** @return Collection<int, User> */
    public function accountRows(): Collection
    {
        $search = trim($this->search);
        $since = now()->subDay();

        return User::query()
            ->where(fn (Builder $query) => $query->whereHas('workflows')->orWhereHas('workflowCredentials'))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('email', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%')
                        ->orWhereHas('accounts', fn (Builder $query): Builder => $query->where('subdomain', 'like', '%'.$search.'%'));
                });
            })
            ->with(['accounts' => fn ($query) => $query->whereNotNull('subdomain')->latest('id')])
            ->withCount([
                'workflows',
                'workflows as active_workflows_count' => fn (Builder $query): Builder => $query->where('is_active', true),
                'workflowRuns as runs_day_count' => fn (Builder $query): Builder => $query->where('created_at', '>=', $since),
                'workflowRuns as failed_day_count' => fn (Builder $query): Builder => $query->where('created_at', '>=', $since)->where('status', RunStatus::FAILED->value),
                'workflowRuns as queued_runs_count' => fn (Builder $query): Builder => $query->where('status', RunStatus::PENDING->value),
                'workflowCredentials',
            ])
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();
    }

    /** @return Collection<int, Workflow> */
    public function recentWorkflows(): Collection
    {
        return Workflow::withoutGlobalScope('tenant')
            ->with(['owner.accounts', 'latestRun'])
            ->withCount(['runs as queued_runs_count' => fn (Builder $query): Builder => $query->where('status', RunStatus::PENDING->value)])
            ->latest('updated_at')
            ->limit(15)
            ->get();
    }

    public function workflowUrl(Workflow $workflow): string
    {
        return WorkflowResource::getUrl('edit', ['record' => $workflow]);
    }
}
