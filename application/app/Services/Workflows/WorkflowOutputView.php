<?php

namespace App\Services\Workflows;

/** A presentation/short-expression projection. Persisted execution data is never changed. */
final class WorkflowOutputView
{
    public static function isCondition(mixed $output): bool
    {
        return is_array($output) && is_bool($output['passed'] ?? null)
            && isset($output['branch'], $output['condition_results']);
    }

    public static function trigger(mixed $input): mixed
    {
        if (!is_array($input)) return $input;
        // Normalized CRM events already identify the entity for this individual run.
        if (is_array($input['item'] ?? null) && in_array($input['entity'] ?? null, ['lead', 'contact', 'company', 'customer', 'task', 'talk', 'message', 'note', 'unsorted'], true)) {
            return self::entity($input['item']);
        }
        if (array_key_exists('body', $input) && (isset($input['headers']) || isset($input['method']) || isset($input['_workflow_start_node_id']) || count($input) === 1)) {
            return self::amoWebhookEntity($input['body']);
        }
        unset($input['_workflow_start_node_id']);
        return self::amoWebhookEntity($input);
    }

    private static function amoWebhookEntity(mixed $body): mixed
    {
        if (!is_array($body) || !is_array($body['account'] ?? null)
            || !isset($body['account']['id'], $body['account']['subdomain'])) return $body;
        $collections = array_intersect_key($body, array_flip(['leads', 'contacts', 'companies', 'customers', 'tasks', 'talks', 'messages', 'notes', 'unsorted']));
        if (count($collections) !== 1) return $body;
        $events = reset($collections);
        if (!is_array($events) || count($events) !== 1) return $body;
        $items = reset($events);
        if (!is_array($items) || !array_is_list($items) || $items === []) return $body;
        foreach ($items as $item) {
            if (!is_array($item) || (!isset($item['id']) && !isset($item['uid']))) return $body;
        }
        // A batch stays a collection: never silently discard the other entities.
        return self::entity(count($items) === 1 ? $items[0] : $items);
    }

    public static function triggerPath(array $input, string $path): mixed
    {
        $visible = self::trigger($input);
        if ($path === '') return $visible;
        if (is_array($visible) && \Illuminate\Support\Arr::has($visible, $path)) return \Illuminate\Support\Arr::get($visible, $path);
        // Existing short expressions may still contain the old transport envelope.
        foreach ([$input, $input['body'] ?? null, $input['payload'] ?? null] as $legacy) {
            if (is_array($legacy) && \Illuminate\Support\Arr::has($legacy, $path)) return \Illuminate\Support\Arr::get($legacy, $path);
        }
        return null;
    }

    public static function value(mixed $output): mixed
    {
        if (!is_array($output)) return $output;
        if (($output['_workflow_loop'] ?? false) === true) return array_key_exists('item', $output) ? $output['item'] : ($output['items'] ?? []);
        if (self::isCondition($output)) return $output['passed'];
        if (self::isSingleContactCreation($output)) return self::createdContact($output);
        // Read results can contain all pages, while the HTTP journal is truncated.
        // Never replace the canonical collection with the last HTTP response.
        if (array_key_exists('data', $output) && isset($output['items']) && array_key_exists('has_more', $output)) {
            return self::entity($output['data'] ?: $output['items']);
        }
        if (isset($output['items']) && (array_key_exists('has_more', $output) || array_key_exists('has_matches', $output))) {
            return self::entity($output['items']);
        }
        if (isset($output['contact'], $output['entity_type']) && $output['entity_type'] === 'contact') return self::entity($output['contact']);
        if (array_key_exists('body', $output) && array_key_exists('status', $output) && array_key_exists('success', $output)) return $output['body'];
        $exchanges = is_array($output['amo_exchange'] ?? null) ? $output['amo_exchange'] : [];
        $last = array_key_last($exchanges);
        if ($last !== null && array_key_exists('body', $exchanges[$last]['response'] ?? [])) return self::entity($exchanges[$last]['response']['body']);
        unset($output['amo_exchange'], $output['amo_exchange_truncated']);
        return $output;
    }

    public static function actionPath(mixed $output, string $path): mixed
    {
        $value = self::value($output);
        // Saved short expressions may still address the old singleton response array.
        if (self::isSingleContactCreation($output) && ($path === '0' || str_starts_with($path, '0.'))) {
            $path = $path === '0' ? '' : substr($path, 2);
        }
        return $path === '' ? $value : (is_array($value) ? \Illuminate\Support\Arr::get($value, $path) : null);
    }

    private static function isSingleContactCreation(mixed $output): bool
    {
        return is_array($output) && ($output['entity_type'] ?? null) === 'contact'
            && in_array($output['action'] ?? null, ['created', 'found_existing'], true)
            && is_numeric($output['entity_id'] ?? null) && (int) $output['entity_id'] > 0;
    }

    private static function createdContact(array $output): array
    {
        $id = (int) $output['entity_id'];
        $contact = ['id' => $id];
        // Select the returned contact by ID, not the last response (which may be a link request).
        foreach ((array) ($output['amo_exchange'] ?? []) as $exchange) {
            $body = $exchange['response']['body'] ?? null;
            if (!is_array($body)) continue;
            foreach ((array) ($body['_embedded']['contacts'] ?? []) as $candidate) {
                if (is_array($candidate) && (string) ($candidate['id'] ?? '') === (string) $id) $contact = $candidate;
            }
        }
        if (is_array($output['contact'] ?? null) && (string) ($output['contact']['id'] ?? '') === (string) $id) {
            $contact = $output['contact'];
        }
        unset($contact['request_id']);
        return self::entity($contact);
    }

    public static function entity(mixed $body): mixed
    {
        if (!is_array($body)) return $body;
        unset($body['_links'], $body['_page'], $body['_total_items']);
        if (isset($body['_embedded']) && is_array($body['_embedded'])) {
            $embedded = $body['_embedded'];
            unset($body['_embedded']);
            if ($body === [] && count($embedded) === 1) return self::entity(reset($embedded));
            $body += $embedded;
        }
        foreach ($body as $key => $value) $body[$key] = self::entity($value);
        return $body;
    }
}
