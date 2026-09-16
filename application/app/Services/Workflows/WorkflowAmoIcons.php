<?php

namespace App\Services\Workflows;

final class WorkflowAmoIcons
{
    /** Inline SVG IDs share one document, including icons inside hidden panels. */
    public static function uniqueReferences(string $svg): string
    {
        $prefix = 'amo-'.\Illuminate\Support\Str::uuid().'-';
        preg_match_all('/\bid=["\']([^"\']+)["\']/', $svg, $matches);
        foreach (array_unique($matches[1]) as $id) {
            $svg = str_replace(
                ['id="'.$id.'"', "id='".$id."'", 'href="#'.$id.'"', "href='#".$id."'", 'url(#'.$id.')'],
                ['id="'.$prefix.$id.'"', "id='".$prefix.$id."'", 'href="#'.$prefix.$id.'"', "href='#".$prefix.$id."'", 'url(#'.$prefix.$id.')'],
                $svg,
            );
        }
        return $svg;
    }

    public static function entity(string $entity, string $fallback = 'heroicon-o-circle-stack'): string
    {
        return match ($entity) {
            'lead', 'leads', 'Сделка', 'Сделки' => 'amocrm-lead',
            'customer', 'customers', 'Покупатель', 'Покупатели' => 'amocrm-customer',
            'task', 'tasks', 'Задача', 'Задачи' => 'amocrm-task',
            'talk', 'talks', 'communication', 'Беседы', 'imBox' => 'amocrm-imbox',
            'message', 'outgoing_message', 'Сообщения' => 'heroicon-o-chat-bubble-left-right',
            'unsorted', 'Неразобранное' => 'heroicon-o-inbox-arrow-down',
            'note', 'notes', 'Примечание', 'Примечания' => 'heroicon-o-document-text',
            'tag', 'tags', 'Тег', 'Теги' => 'heroicon-o-tag',
            'company', 'companies', 'Компания', 'Компании' => 'heroicon-o-building-office-2',
            'contact', 'contacts', 'Контакт', 'Контакты' => 'heroicon-o-user',
            'catalog', 'catalogs', 'elements', 'catalog_fields', 'Каталоги', 'Списки', 'Списки и товары', 'Счета' => 'amocrm-catalog',
            default => $fallback,
        };
    }

    public static function action(string $type, array $config, string $fallback): string
    {
        if (!str_starts_with($type, 'amocrm_')) return $fallback;
        if ($type === 'amocrm_contact_leads') return self::entity('lead', $fallback);
        if ($type === 'amocrm_read') {
            $parts = explode('.', $config['operation'] ?? '');
            if (in_array('tags', $parts, true)) return self::entity('tag');
            return self::entity(in_array('notes', $parts, true) ? 'note' : $parts[0], $fallback);
        }
        foreach (['note', 'task', 'company', 'contact', 'customer', 'lead', 'catalog'] as $entity) {
            if (preg_match('/(?:^|_)'. $entity .'s?(?:_|$)/', $type)) return self::entity($entity, $fallback);
        }
        return self::entity((string) ($config['target_entity'] ?? $config['entity'] ?? ''), $fallback);
    }

    public static function trigger(string $type, string $fallback): string
    {
        if (!str_starts_with($type, 'amocrm-')) return $fallback;
        if (str_ends_with($type, '-message')) return self::entity('message');
        if (str_ends_with($type, '-unsorted')) return self::entity('unsorted');
        if (str_contains($type, '-talk') || str_contains($type, 'chat-template-review')) return 'amocrm-imbox';
        foreach (['note', 'company', 'contact', 'lead', 'customer', 'task'] as $entity) {
            if (preg_match('/(?:^|-)'. $entity .'s?(?:-|$)/', $type)) return self::entity($entity, $fallback);
        }
        return $fallback;
    }
}
