<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;

class WorkflowAnalytics extends Page
{
    protected static string $resource = WorkflowResource::class;
    protected string $view = 'filament.workflow-builder.workflow-analytics-page';
    protected static ?string $title = 'Аналитика';
    protected Width|string|null $maxContentWidth = Width::Full;
    public int $period = 14;

    public function mount(): void { abort_unless(WorkflowResource::canViewAny(), 403); }
    public function getBreadcrumbs(): array { return []; }
    public function summary(): array { return \App\Services\Workflows\WorkflowAnalytics::summarize((int)auth()->id(), $this->period); }
}
