@props(['icon', 'amo' => false, 'type' => null])
@php
    $isAmo = $amo || str_starts_with($icon, 'amocrm-');
    // Keep one outline icon system in both the library and canvas. Brand logos keep their original artwork.
    $icon = match ($icon) {
        'amocrm-lead' => 'heroicon-o-currency-dollar',
        'amocrm-customer' => 'heroicon-o-users',
        'amocrm-task' => 'heroicon-o-check-circle',
        'amocrm-imbox' => 'heroicon-o-chat-bubble-left-ellipsis',
        'amocrm-catalog' => 'heroicon-o-clipboard-document-list',
        'amocrm-button' => 'heroicon-o-cursor-arrow-rays',
        default => $icon,
    };
    $html = \Filament\Support\generate_icon_html($icon, null, $attributes->class([
        'workflow-amo-icon' => $isAmo,
        'workflow-code-icon' => $type === 'workflow_javascript',
        'workflow-control-icon' => in_array($type, ['condition', 'control-condition', 'workflow_delay'], true),
    ]))?->toHtml() ?? '';
    if (str_starts_with($icon, 'amocrm-') || str_starts_with($icon, 'service-')) {
        $html = \App\Services\Workflows\WorkflowAmoIcons::uniqueReferences($html);
    }
@endphp
{!! $html !!}
