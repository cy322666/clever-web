<?php

namespace App\Services\Workflows;

use App\Models\Workflows\Workflow;
use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;

final class WorkflowSidebar
{
    public static function folderOverview(): array
    {
        $rows = Workflow::query()->where('user_id', auth()->id())
            ->selectRaw('group_name, count(*) as aggregate')->groupBy('group_name')
            ->get();
        $counts = $rows->pluck('aggregate', 'group_name');

        return [
            'total' => $rows->sum('aggregate'),
            'root' => (int) $rows->filter(fn (Workflow $row): bool => blank($row->group_name))->sum('aggregate'),
            'folders' => collect(WorkflowFolders::options())->map(fn (string $name): array => [
                'name' => $name,
                'count' => (int) ($counts[$name] ?? 0),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, icon: string, count: int}>
     */
    public static function launchTypeOverview(): array
    {
        $groups = self::launchTypeGroups();
        $counts = array_fill_keys(array_keys($groups), 0);

        Workflow::query()
            ->where('user_id', auth()->id())
            ->get(['id', 'definition'])
            ->each(function (Workflow $workflow) use ($groups, &$counts): void {
                $types = self::workflowStartTypes($workflow);

                foreach ($groups as $key => $group) {
                    if (array_intersect($types, $group['types']) !== []) {
                        $counts[$key]++;
                    }
                }
            });

        return collect($groups)
            ->map(fn (array $group, string $key): array => [
                'key' => $key,
                'label' => $group['label'],
                'icon' => $group['icon'],
                'count' => $counts[$key],
            ])
            ->filter(fn (array $group): bool => $group['count'] > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{label: string, icon: string, types: array<int, string>}>
     */
    public static function launchTypeGroups(): array
    {
        return [
            'digital-pipeline' => [
                'label' => 'Digital Pipeline',
                'icon' => 'amocrm-digital-pipeline',
                'types' => ['digital-pipeline'],
            ],
            'buttons' => [
                'label' => 'Кнопки',
                'icon' => 'amocrm-button',
                'types' => ['amo-button'],
            ],
            'bulk-actions' => [
                'label' => 'Массовое действие',
                'icon' => 'heroicon-o-rectangle-stack',
                'types' => ['amo-bulk'],
            ],
            'webhooks' => [
                'label' => 'Webhooks',
                'icon' => 'heroicon-o-globe-alt',
                'types' => ['generic-webhook'],
            ],
            'amocrm-events' => [
                'label' => 'События amoCRM',
                'icon' => 'heroicon-o-bolt',
                'types' => array_map(
                    fn (string $triggerClass): string => $triggerClass::type(),
                    AmoCrmWebhookTriggerCatalog::classes(),
                ),
            ],
            'manual' => [
                'label' => 'Ручной запуск',
                'icon' => 'heroicon-o-play',
                'types' => ['manual'],
            ],
            'schedule' => [
                'label' => 'Расписание',
                'icon' => 'heroicon-o-clock',
                'types' => ['schedule'],
            ],
            'workflow' => [
                'label' => 'Из другого сценария',
                'icon' => 'heroicon-o-arrow-right-circle',
                'types' => ['workflow-completed'],
            ],
            'date' => [
                'label' => 'По дате',
                'icon' => 'heroicon-o-calendar-days',
                'types' => ['date-condition'],
            ],
        ];
    }

    private static function workflowStartTypes(Workflow $workflow): array
    {
        try {
            return collect(WorkflowStartNodes::all($workflow->definition ?? []))
                ->pluck('type')
                ->filter(fn (mixed $type): bool => is_string($type) && $type !== '')
                ->unique()
                ->values()
                ->all();
        } catch (\InvalidArgumentException) {
            return [];
        }
    }
}
