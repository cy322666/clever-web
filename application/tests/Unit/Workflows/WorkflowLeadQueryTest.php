<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowLeadQuery;
use Illuminate\Support\Facades\Http;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowLeadQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
    }

    public function test_it_builds_status_range_and_custom_field_filters(): void
    {
        $query = WorkflowLeadQuery::build(['pipeline_id' => 12, 'status_id' => 34, 'limit' => 25, 'page' => 2, 'responsible_user_ids' => [56], 'filters' => [
            ['field' => 'price', 'operator' => 'from', 'value' => '0'],
            ['field' => 'updated_at', 'operator' => 'to', 'value' => '1726000000'],
            ['field' => 'custom:789', 'operator' => 'eq', 'value' => '123'],
        ]]);
        $this->assertSame([['pipeline_id' => 12, 'status_id' => 34]], $query['filter']['statuses']);
        $this->assertSame(0.0, $query['filter']['price']['from']);
        $this->assertSame(1726000000, $query['filter']['updated_at']['to']);
        $this->assertSame(['123'], $query['filter']['custom_fields_values'][789]);
        $this->assertSame(25, $query['limit']);
    }

    public function test_invalid_filters_are_not_silently_dropped(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkflowLeadQuery::build(['status_id' => 34]);
    }

    public function test_repeated_range_bounds_are_intersected(): void
    {
        $query = WorkflowLeadQuery::build(['filters' => [
            ['field' => 'price', 'operator' => 'from', 'value' => '100'],
            ['field' => 'price', 'operator' => 'from', 'value' => '50'],
            ['field' => 'custom:789', 'operator' => 'from', 'value' => '1'],
            ['field' => 'custom:789', 'operator' => 'to', 'value' => '5'],
        ]]);
        $this->assertSame(100.0, $query['filter']['price']['from']);
        $this->assertSame(['from' => '1', 'to' => '5'], $query['filter']['custom_fields_values'][789]);
    }

    public function test_conflicting_ranges_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WorkflowLeadQuery::build(['filters' => [
            ['field' => 'price', 'operator' => 'from', 'value' => '100'],
            ['field' => 'price', 'operator' => 'to', 'value' => '50'],
        ]]);
    }

    public function test_an_empty_amo_response_is_an_empty_page_not_an_error(): void
    {
        Http::fake(['https://workflow-query.test/api/v4/leads*' => Http::response('', 204)]);
        $executor = app(WorkflowAmoCrmActionExecutor::class);
        $result = (new \ReflectionMethod($executor, 'queryLeads'))->invoke($executor,
            (new Account)->forceFill(['endpoint' => 'https://workflow-query.test', 'access_token' => 'test-only']), []);
        $this->assertTrue($result['success']);
        $this->assertSame([], $result['output']['items']);
        $this->assertFalse($result['output']['has_more']);
    }

    public function test_the_executor_uses_get_and_returns_page_metadata_and_items(): void
    {
        Http::fake(['https://workflow-query.test/api/v4/leads*' => Http::response(['_embedded' => ['leads' => [['id' => 123, 'name' => 'Сделка']]], '_links' => ['next' => ['href' => 'ignored']]])]);
        $executor = app(WorkflowAmoCrmActionExecutor::class);
        $method = new \ReflectionMethod($executor, 'queryLeads');
        $result = $method->invoke($executor, (new Account)->forceFill(['endpoint' => 'https://workflow-query.test', 'access_token' => 'test-only']), ['pipeline_id' => 12]);
        $this->assertTrue($result['success']);
        $this->assertSame(123, $result['output']['items'][0]['id']);
        $this->assertSame(1, $result['output']['count']);
        $this->assertSame(2, $result['output']['next_page']);
        Http::assertSent(function ($request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET' && $query['filter']['pipeline_id'] === '12';
        });
        Http::assertSentCount(1);
    }
}
