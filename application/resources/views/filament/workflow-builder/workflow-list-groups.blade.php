@php
    use App\Models\Workflows\Workflow;

    $currentGrouping = (string) ($this->tableGrouping ?? '');
    $groupCounts = Workflow::query()
        ->selectRaw("COALESCE(NULLIF(group_name, ''), '__without_group__') as group_key, COUNT(*) as total")
        ->groupBy('group_key')
        ->pluck('total', 'group_key')
        ->map(fn (mixed $total): int => (int) $total);
    $groupOptions = Workflow::groupOptions();
    $totalCount = $groupCounts->sum();

    $items = collect([
        [
            'label' => 'Все',
            'value' => '',
            'count' => $totalCount,
        ],
        [
            'label' => 'Без группы',
            'value' => '__without_group__:asc',
            'count' => $groupCounts->get('__without_group__', 0),
        ],
    ])->merge(collect($groupOptions)->map(fn (string $groupName): array => [
        'label' => $groupName,
        'value' => $groupName . ':asc',
        'count' => $groupCounts->get($groupName, 0),
    ]));
@endphp

<aside class="workflow-list-groups" aria-label="Разделы процессов">
    <div class="workflow-list-groups__title">Разделы</div>

    <div class="workflow-list-groups__items">
        @foreach ($items as $item)
            <button
                type="button"
                wire:click="$set('tableGrouping', @js($item['value']))"
                @class([
                    'workflow-list-groups__item',
                    'workflow-list-groups__item--active' => $currentGrouping === $item['value'],
                ])
            >
                <span class="workflow-list-groups__name">{{ $item['label'] }}</span>
                <span class="workflow-list-groups__count">{{ $item['count'] }}</span>
            </button>
        @endforeach
    </div>
</aside>
