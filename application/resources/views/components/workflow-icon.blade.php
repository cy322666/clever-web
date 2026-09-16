@props(['icon', 'amo' => false, 'type' => null])
@php
    $isAmo = $amo || str_starts_with($icon, 'amocrm-');
    $html = \Filament\Support\generate_icon_html($icon, null, $attributes->class([
        'workflow-amo-icon' => $isAmo,
        'workflow-code-icon' => $type === 'workflow_javascript',
        'workflow-control-icon' => in_array($type, ['condition', 'control-condition', 'workflow_delay'], true),
    ]))?->toHtml() ?? '';
    if (str_starts_with($icon, 'amocrm-')) {
        $html = \App\Services\Workflows\WorkflowAmoIcons::uniqueReferences($html);
    }
@endphp
{!! $html !!}
