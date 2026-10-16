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
        $envelope = array_key_exists('_workflow_start_node_id', $input) || isset($input['source'])
            || isset($input['workflow_id']) || isset($input['received_at'])
            || isset($input['triggered_at']) || isset($input['headers']) || isset($input['method']);
        if (array_key_exists('body', $input) && ($envelope || count($input) === 1)) {
            return self::amoWebhookEntity($input['body']);
        }
        if (array_key_exists('payload', $input) && $envelope) {
            return self::amoWebhookEntity($input['payload']);
        }
        if ($envelope) {
            // Schedule and workflow-to-workflow starts may have no entity body.
            // Do not show their transport and scheduling fields as input data.
            return self::entity(array_diff_key($input, array_flip([
                '_workflow_start_node_id', 'source', 'event', 'action', 'entity', 'entity_id',
                'workflow_id', 'source_workflow_id', 'source_workflow_run_id', 'is_manual',
                'account', 'widget', 'webhook', 'headers', 'query', 'method', 'url', 'path',
                'ip', 'raw_body', 'received_at', 'triggered_at', 'frequency', 'cron_expression',
            ])));
        }
        return self::amoWebhookEntity($input);
    }

    private static function amoWebhookEntity(mixed $body): mixed
    {
        if (!is_array($body) || !is_array($body['account'] ?? null)
            || !isset($body['account']['id'], $body['account']['subdomain'])) return $body;
        $collections = array_intersect_key($body, array_flip(['leads', 'contacts', 'companies', 'customers', 'tasks', 'talks', 'messages', 'notes', 'unsorted']));
        if ($collections === []) return $body;
        $entities = [];
        foreach ($collections as $type => $events) {
            if (!is_array($events)) return array_diff_key($body, ['account' => true]);
            foreach ($events as $items) {
                if (!is_array($items) || !array_is_list($items)) return array_diff_key($body, ['account' => true]);
                foreach ($items as $item) {
                    if (!is_array($item) || (!isset($item['id']) && !isset($item['uid']))) return array_diff_key($body, ['account' => true]);
                    $entities[$type][] = $item;
                }
            }
        }
        if (count($entities) !== 1) return self::entity($entities);
        $items = reset($entities);
        // A batch stays a collection: never silently discard the other entities.
        return self::entity(count($items) === 1 ? $items[0] : $items);
    }

    public static function triggerPath(array $input, string $path): mixed
    {
        $visible = self::trigger($input);
        if ($path === '') return $visible;
        $missing = new \stdClass;
        $found = WorkflowValuePath::get($visible, $path, $missing);
        if ($found !== $missing) return $found;
        // Existing short expressions may still contain the old transport envelope.
        foreach ([$input, $input['body'] ?? null, $input['payload'] ?? null] as $legacy) {
            $found = WorkflowValuePath::get($legacy, $path, $missing);
            if ($found !== $missing) return $found;
        }
        return null;
    }

    public static function value(mixed $output): mixed
    {
        if (!is_array($output)) return $output;
        if (($output['_workflow_loop'] ?? false) === true) return array_key_exists('item', $output) ? $output['item'] : ($output['items'] ?? []);
        if (self::isCondition($output)) return $output['passed'];
        if (self::isSingleEntityCreation($output)) return self::createdEntity($output);
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

    /** Keep empty HTTP bodies from hiding a node's recorded result in the UI. */
    public static function displayValue(mixed $output): mixed
    {
        $value = self::value($output);
        if (!in_array($value, [[], '', null], true) || !is_array($output)) return $value;

        // An empty CRM collection is still a real business result. Falling back
        // to the raw journal would expose request, pagination and transport data.
        if (is_array($output['items'] ?? null)
            && (array_key_exists('has_more', $output) || array_key_exists('has_matches', $output)
                || array_key_exists('count', $output))) {
            $visible = ['items' => self::entity($output['items'])];
            foreach (['count', 'input_count'] as $counter) {
                if (array_key_exists($counter, $output)) $visible[$counter] = $output[$counter];
            }
            return $visible;
        }

        unset($output['amo_exchange'], $output['amo_exchange_truncated']);
        return $output !== [] ? $output : $value;
    }

    public static function actionPath(mixed $output, string $path): mixed
    {
        // A list is shown as its items, but its public counters remain addressable.
        // Otherwise $("Фильтр списка").count resolves to null after projection.
        if (is_array($output) && isset($output['items']) && is_array($output['items'])
            && in_array($path, ['count', 'input_count', 'has_matches', 'has_more'], true)
            && array_key_exists($path, $output)) {
            return $output[$path];
        }
        $value = self::value($output);
        // Saved short expressions may still address the old singleton response array.
        if (self::isSingleEntityCreation($output) && ($path === '0' || str_starts_with($path, '0.'))) {
            $path = $path === '0' ? '' : substr($path, 2);
        }
        return WorkflowValuePath::get($value, $path);
    }

    /** The data arriving at a node, without a previous node's transport journal. */
    public static function input(mixed $input): mixed
    {
        if (!is_array($input)) return $input;
        if (is_array($input['items'] ?? null) && array_key_exists('count', $input)) {
            return ['count' => $input['count'], 'items' => self::entity($input['items'])];
        }
        if (isset($input['_workflow_start_node_id']) || isset($input['source'], $input['payload'])
            || (isset($input['entity'], $input['item']) && is_array($input['item']))) {
            return self::trigger($input);
        }
        return self::value($input);
    }

    private static function isSingleEntityCreation(mixed $output): bool
    {
        return is_array($output) && in_array($output['entity_type'] ?? null, ['contact', 'lead'], true)
            && in_array($output['action'] ?? null, ['created', 'found_existing'], true)
            && is_numeric($output['entity_id'] ?? null) && (int) $output['entity_id'] > 0;
    }

    private static function createdEntity(array $output): array
    {
        $id = (int) $output['entity_id'];
        $type = $output['entity_type'];
        $entity = ['id' => $id];
        // Select the created entity by ID, not the last response (which may be a link request).
        foreach ((array) ($output['amo_exchange'] ?? []) as $exchange) {
            $body = $exchange['response']['body'] ?? null;
            if (!is_array($body)) continue;
            foreach ((array) ($body['_embedded'][$type.'s'] ?? []) as $candidate) {
                if (is_array($candidate) && (string) ($candidate['id'] ?? '') === (string) $id) $entity = $candidate;
            }
        }
        if (is_array($output[$type] ?? null) && (string) ($output[$type]['id'] ?? '') === (string) $id) {
            $entity = $output[$type];
        }
        unset($entity['request_id']);
        return self::entity($entity);
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
