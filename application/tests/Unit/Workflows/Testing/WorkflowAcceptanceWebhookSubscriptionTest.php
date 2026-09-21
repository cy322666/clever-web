<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Services\Workflows\Testing\WorkflowAcceptanceWebhookSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Pure contract tests: no Laravel bootstrap, database, HTTP, or real sleeps. */
final class WorkflowAcceptanceWebhookSubscriptionTest extends TestCase
{
    private const DESTINATION = 'https://app.clevercrm.pro/api/workflows/webhook/123/qa-secret-never-log';
    private const EVENTS = ['add_lead', 'update_lead'];

    public function test_install_verifies_exact_enabled_subscription_and_posts_once(): void
    {
        [$subscription, $state] = $this->harness(fn ($method) => $method === 'GET' ? $this->page([$this->hook()]) : []);

        $result = $subscription->install(self::DESTINATION, self::EVENTS);

        $this->assertSame('installed', $result['status']);
        $this->assertSame(91, $result['webhook_id']);
        $this->assertEqualsCanonicalizing(self::EVENTS, $result['expected_events']);
        $posts = $this->calls($state, 'POST');
        $this->assertCount(1, $posts);
        $this->assertSame('/api/v4/webhooks', $posts[0]['path']);
        $this->assertSame(self::DESTINATION, $posts[0]['body']['destination']);
        $this->assertEqualsCanonicalizing(self::EVENTS, $posts[0]['body']['settings']);
        $this->assertNotEmpty($this->calls($state, 'GET'));
    }

    public function test_install_waits_for_delayed_visibility_without_reposting(): void
    {
        $reads = 0;
        [$subscription, $state] = $this->harness(function ($method) use (&$reads) {
            if ($method !== 'GET') return [];
            return ++$reads < 3 ? $this->page([]) : $this->page([$this->hook()]);
        });

        $result = $subscription->install(self::DESTINATION, self::EVENTS);

        $this->assertSame('installed', $result['status']);
        $this->assertGreaterThanOrEqual(3, $reads);
        $this->assertGreaterThanOrEqual(4, $state->time);
        $this->assertCount(1, $this->calls($state, 'POST'));
    }

    public function test_install_waits_until_all_requested_events_are_present(): void
    {
        $reads = 0;
        [$subscription, $state] = $this->harness(function ($method) use (&$reads) {
            if ($method !== 'GET') return [];
            return $this->page([$this->hook(['settings' => ++$reads < 3 ? ['add_lead'] : self::EVENTS])]);
        });

        $result = $subscription->install(self::DESTINATION, self::EVENTS);

        $this->assertSame('installed', $result['status']);
        $this->assertGreaterThanOrEqual(3, $reads);
        $this->assertCount(1, $this->calls($state, 'POST'));
    }

    #[DataProvider('unacceptableSubscriptions')]
    public function test_install_never_confirms_partial_disabled_unknown_or_other_destination(array $hook): void
    {
        [$subscription, $state] = $this->harness(fn ($method) => $method === 'GET' ? $this->page([$hook]) : []);

        $this->failure(fn () => $subscription->install(self::DESTINATION, self::EVENTS));

        $this->assertCount(1, $this->calls($state, 'POST'));
        $this->assertGreaterThan(0, count($this->calls($state, 'GET')));
        $this->assertLessThanOrEqual(50, $state->time);
    }

    public static function unacceptableSubscriptions(): array
    {
        $base = ['id' => 91, 'destination' => self::DESTINATION, 'disabled' => false, 'settings' => self::EVENTS];
        $unknown = $base;
        unset($unknown['disabled']);

        return [
            'partial event list' => [array_replace($base, ['settings' => ['add_lead']])],
            'disabled' => [array_replace($base, ['disabled' => true])],
            'missing enabled evidence' => [$unknown],
            'different destination' => [array_replace($base, ['destination' => self::DESTINATION.'-other'])],
        ];
    }

    public function test_install_searches_all_inventory_pages(): void
    {
        [$subscription, $state] = $this->harness(function ($method, $path, $body, $query) {
            if ($method !== 'GET') return [];
            return ($query['page'] ?? 1) === 1
                ? $this->page([$this->hook(['destination' => 'https://example.test/other'])], 'https://contract.amocrm.ru/api/v4/webhooks?page=2')
                : $this->page([$this->hook()]);
        });

        $result = $subscription->install(self::DESTINATION, self::EVENTS);

        $this->assertSame('installed', $result['status']);
        $reads = $this->calls($state, 'GET');
        $this->assertGreaterThanOrEqual(2, count($reads));
        $this->assertSame(1, $reads[0]['query']['page']);
        $this->assertSame(2, $reads[1]['query']['page']);
        $this->assertSame(250, $reads[1]['query']['limit']);
        $this->assertSame('/api/v4/webhooks', $reads[1]['path']);
    }

    public function test_ambiguous_post_outcome_can_be_resolved_by_readback_without_another_post(): void
    {
        [$subscription, $state] = $this->harness(function ($method) {
            if ($method === 'POST') throw new RuntimeException('Connection timed out after sending request');
            return $this->page([$this->hook()]);
        });

        $result = $subscription->install(self::DESTINATION, self::EVENTS);

        $this->assertSame('installed', $result['status']);
        $this->assertCount(1, $this->calls($state, 'POST'));
        $this->assertNotEmpty($this->calls($state, 'GET'));
    }

    public function test_unresolved_post_outcome_fails_without_retrying_creation(): void
    {
        [$subscription, $state] = $this->harness(function ($method) {
            if ($method === 'POST') throw new RuntimeException('Connection timed out after sending request');
            return $this->page([]);
        });

        $this->failure(fn () => $subscription->install(self::DESTINATION, self::EVENTS));

        $this->assertCount(1, $this->calls($state, 'POST'));
        $this->assertLessThanOrEqual(50, $state->time);
    }

    public function test_cleanup_deletes_exact_destination_even_if_inventory_never_shows_it(): void
    {
        [$subscription, $state] = $this->harness(fn ($method) => $method === 'GET' ? $this->page([]) : []);

        $result = $subscription->remove(self::DESTINATION);

        $this->assertSame('removed', $result['status']);
        $this->assertTrue($result['removed']);
        $this->assertSame('DELETE', $state->calls[0]['method']);
        $this->assertSame(['destination' => self::DESTINATION], $state->calls[0]['body']);
        $this->assertCount(1, $this->calls($state, 'DELETE'));
        $this->assertGreaterThanOrEqual(3, $result['absence_confirmations']);
        $this->assertGreaterThanOrEqual(12, $result['settled_seconds']);
        $this->assertGreaterThanOrEqual(12, $state->time);
    }

    public function test_delayed_reappearance_triggers_scoped_delete_and_restarts_absence_window(): void
    {
        $reads = 0;
        $lastDeleteAt = 0.0;
        [$subscription, $state] = $this->harness(function ($method, $path, $body, $query, $state) use (&$reads, &$lastDeleteAt) {
            if ($method === 'DELETE') {
                $lastDeleteAt = $state->time;
                return [];
            }
            return $this->page(++$reads === 3 ? [$this->hook()] : []);
        });

        $result = $subscription->remove(self::DESTINATION);

        $this->assertTrue($result['removed']);
        $this->assertGreaterThanOrEqual(2, count($this->calls($state, 'DELETE')));
        $this->assertGreaterThanOrEqual(12, $state->time - $lastDeleteAt);
        foreach ($this->calls($state, 'DELETE') as $call) $this->assertSame(['destination' => self::DESTINATION], $call['body']);
    }

    public function test_cleanup_ignores_other_subscriptions_and_does_not_delete_them(): void
    {
        [$subscription, $state] = $this->harness(fn ($method) => $method === 'GET'
            ? $this->page([$this->hook(['destination' => 'https://other-client.test/business-hook'])]) : []);

        $result = $subscription->remove(self::DESTINATION);

        $this->assertTrue($result['removed']);
        $this->assertCount(1, $this->calls($state, 'DELETE'));
        $this->assertSame(['destination' => self::DESTINATION], $this->calls($state, 'DELETE')[0]['body']);
    }

    public function test_cleanup_detects_target_on_later_page_before_confirming_absence(): void
    {
        $deleteCount = 0;
        [$subscription, $state] = $this->harness(function ($method, $path, $body, $query) use (&$deleteCount) {
            if ($method === 'DELETE') {
                $deleteCount++;
                return [];
            }
            if (($query['page'] ?? 1) === 1) return $this->page([], '/api/v4/webhooks?page=2');
            return $this->page($deleteCount === 1 ? [$this->hook()] : []);
        });

        $result = $subscription->remove(self::DESTINATION);

        $this->assertTrue($result['removed']);
        $this->assertSame(2, $deleteCount);
        $this->assertGreaterThanOrEqual(3, $result['absence_confirmations']);
        $this->assertNotEmpty(array_filter($this->calls($state, 'GET'), fn ($call) => ($call['query']['page'] ?? 0) === 2));
    }

    public function test_ambiguous_delete_is_resolved_only_after_stable_absence(): void
    {
        [$subscription, $state] = $this->harness(function ($method) {
            if ($method === 'DELETE') throw new RuntimeException('DELETE transport outcome unknown');
            return $this->page([]);
        });

        $result = $subscription->remove(self::DESTINATION);

        $this->assertTrue($result['removed']);
        $this->assertGreaterThanOrEqual(12, $state->time);
        $this->assertCount(1, $this->calls($state, 'DELETE'));
    }

    public function test_persistently_present_hook_cannot_report_successful_cleanup(): void
    {
        [$subscription, $state] = $this->harness(fn ($method) => $method === 'GET' ? $this->page([$this->hook()]) : []);

        $this->failure(fn () => $subscription->remove(self::DESTINATION));

        $this->assertLessThanOrEqual(50, $state->time);
        foreach ($this->calls($state, 'DELETE') as $call) $this->assertSame(['destination' => self::DESTINATION], $call['body']);
    }

    public function test_unavailable_inventory_cannot_confirm_cleanup(): void
    {
        [$subscription, $state] = $this->harness(function ($method) {
            if ($method === 'GET') throw new RuntimeException('amoCRM unavailable');
            return [];
        });

        $this->failure(fn () => $subscription->remove(self::DESTINATION));

        $this->assertCount(1, $this->calls($state, 'DELETE'));
        $this->assertLessThanOrEqual(50, $state->time);
    }

    public function test_interrupted_inventory_reads_reset_the_continuous_absence_window(): void
    {
        $reads = 0;
        $failedReadAt = 0.0;
        [$subscription, $state] = $this->harness(function ($method, $path, $body, $query, $state) use (&$reads, &$failedReadAt) {
            if ($method !== 'GET') return [];
            if (++$reads === 4) {
                $failedReadAt = $state->time;
                throw new RuntimeException('Transient GET failure');
            }
            return $this->page([]);
        });

        $result = $subscription->remove(self::DESTINATION);

        $this->assertTrue($result['removed']);
        $this->assertGreaterThanOrEqual(12, $state->time - $failedReadAt);
        $this->assertGreaterThanOrEqual(12, $result['settled_seconds']);
        $this->assertCount(1, $this->calls($state, 'DELETE'));
    }

    #[DataProvider('malformedInventories')]
    public function test_malformed_or_unstructured_empty_inventory_never_confirms_absence(array $response): void
    {
        [$subscription, $state] = $this->harness(fn ($method) => $method === 'GET' ? $response : []);

        $this->failure(fn () => $subscription->remove(self::DESTINATION));

        $this->assertCount(1, $this->calls($state, 'DELETE'));
        $this->assertLessThanOrEqual(50, $state->time);
    }

    public static function malformedInventories(): array
    {
        return [
            'unstructured empty response' => [[]],
            'missing list' => [['_embedded' => []]],
            'null list' => [['_embedded' => ['webhooks' => null]]],
            'error envelope' => [['error' => 'upstream unavailable']],
        ];
    }

    public function test_diagnostic_trace_and_errors_never_expose_signed_destination_or_raw_transport_message(): void
    {
        [$subscription, $state] = $this->harness(function ($method) {
            if ($method === 'POST') throw new RuntimeException('POST '.self::DESTINATION.' Bearer raw-private-token');
            return $this->page([]);
        });

        $error = $this->failure(fn () => $subscription->install(self::DESTINATION, self::EVENTS));
        $diagnostics = json_encode($state->traces, JSON_THROW_ON_ERROR).$error->getMessage();

        foreach ([self::DESTINATION, 'qa-secret-never-log', 'raw-private-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $diagnostics);
        }
        $this->assertNotEmpty($state->traces);
    }

    public function test_untrusted_next_url_is_never_followed_and_pagination_is_bounded(): void
    {
        [$subscription, $state] = $this->harness(fn ($method) => $method === 'GET'
            ? $this->page([], 'https://attacker.test/private-token?next=forever') : []);

        $this->failure(fn () => $subscription->install(self::DESTINATION, self::EVENTS));

        $reads = $this->calls($state, 'GET');
        $this->assertNotEmpty($reads);
        foreach ($reads as $call) {
            $this->assertSame('/api/v4/webhooks', $call['path']);
            $this->assertLessThanOrEqual(20, $call['query']['page']);
            $this->assertGreaterThanOrEqual(1, $call['query']['page']);
            $this->assertSame(250, $call['query']['limit']);
        }
        $this->assertLessThanOrEqual(50, $state->time);
        $this->assertStringNotContainsString('attacker.test', json_encode($state->traces, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $this->calls($state, 'POST'));
    }

    public function test_unique_unending_pages_stop_at_twenty_per_inventory_attempt(): void
    {
        [$subscription, $state] = $this->harness(function ($method, $path, $body, $query) {
            if ($method !== 'GET') return [];
            $page = $query['page'];
            return $this->page([
                $this->hook(['id' => $page, 'destination' => 'https://other-client.test/hook/'.$page]),
            ], 'https://attacker.test/next?page='.($page + 1));
        });

        $this->failure(fn () => $subscription->install(self::DESTINATION, self::EVENTS));

        $pages = array_map(fn ($call) => $call['query']['page'], $this->calls($state, 'GET'));
        $this->assertSame(20, max($pages));
        $this->assertLessThanOrEqual(50, $state->time);
        $this->assertCount(1, $this->calls($state, 'POST'));
        foreach ($this->calls($state, 'GET') as $call) $this->assertSame('/api/v4/webhooks', $call['path']);
    }

    /** @return array{WorkflowAcceptanceWebhookSubscription, object} */
    private function harness(callable $response): array
    {
        $state = (object) ['time' => 0.0, 'calls' => [], 'traces' => [], 'sleeps' => []];
        $service = new WorkflowAcceptanceWebhookSubscription(
            function (string $method, string $path, array $body = [], array $query = []) use ($state, $response): array {
                $state->calls[] = compact('method', 'path', 'body', 'query');
                if (count($state->calls) > 1500) throw new \LogicException('Fake request bound exceeded');
                return $response($method, $path, $body, $query, $state);
            },
            static function (array $event) use ($state): void { $state->traces[] = $event; },
            function (float $seconds) use ($state): void {
                $this->assertGreaterThan(0, $seconds);
                $state->sleeps[] = $seconds;
                $state->time += $seconds;
            },
            static fn (): float => $state->time,
        );

        return [$service, $state];
    }

    private function hook(array $overrides = []): array
    {
        return array_replace(['id' => 91, 'destination' => self::DESTINATION, 'disabled' => false, 'settings' => self::EVENTS], $overrides);
    }

    private function page(array $hooks, ?string $next = null): array
    {
        $response = ['_embedded' => ['webhooks' => $hooks]];
        if ($next !== null) $response['_links']['next']['href'] = $next;
        return $response;
    }

    private function calls(object $state, string $method): array
    {
        return array_values(array_filter($state->calls, static fn (array $call): bool => $call['method'] === $method));
    }

    private function failure(callable $operation): RuntimeException
    {
        try {
            $operation();
        } catch (RuntimeException $error) {
            $this->assertNotSame('', $error->getMessage());
            return $error;
        }
        $this->fail('Expected a bounded, explicit verification failure.');
    }
}
