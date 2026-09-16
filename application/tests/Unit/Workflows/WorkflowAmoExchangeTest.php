<?php
namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use Illuminate\Support\Facades\Http;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowAmoExchangeTest extends TestCase
{
    public function test_contact_creation_records_the_real_post_body_and_deduplication_records_no_post(): void
    {
        WorkflowCanvasDatabase::prepare(); Http::preventStrayRequests();
        $account = (new Account)->forceFill(['id' => 1, 'endpoint' => 'https://contact-body.test', 'access_token' => 'test-only']);
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(\App\Services\Workflows\WorkflowAmoCrmLoopGuard::class));
        (new \ReflectionProperty($executor, 'captureAmoExchange'))->setValue($executor, true);
        $create = new \ReflectionMethod($executor, 'createEntity');
        $client = (new \ReflectionClass($create->getParameters()[0]->getType()->getName()))->newInstanceWithoutConstructor();
        $existing = false;
        Http::fake(function ($request) use (&$existing) {
            return $request->method() === 'POST'
                ? Http::response(['_embedded' => ['contacts' => [['id' => 77]]]], 201)
                : Http::response(['_embedded' => ['contacts' => $existing ? [['id' => 88, 'name' => 'Test Contact', 'custom_fields_values' => []]] : []]]);
        });
        $result = $create->invoke($executor, $client, $account, 'contact', ['name' => 'Test Contact', 'responsible_user_id' => 42], null);
        $result = (new \ReflectionMethod($executor, 'withAmoExchange'))->invoke($executor, $result, null);
        $post = collect($result['output']['amo_exchange'])->firstWhere('request.method', 'POST');
        $this->assertSame([['name' => 'Test Contact', 'responsible_user_id' => 42]], $post['request']['body']);
        $this->assertSame(77, $result['output']['entity_id']);

        $existing = true;
        $result = $create->invoke($executor, $client, $account, 'contact', ['name' => 'Test Contact'], null);
        $this->assertTrue($result['output']['deduplicated']);
        $this->assertFalse($result['output']['create_request']['sent']);
        $this->assertSame([['name' => 'Test Contact']], $result['output']['create_request']['body']);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'POST'));
    }

    public function test_actual_http_request_and_response_are_recorded_without_credentials(): void
    {
        WorkflowCanvasDatabase::prepare(); Http::preventStrayRequests();
        Http::fake(['https://example.amocrm.ru/*'=>Http::response(['id'=>123,'access_token'=>'private'],201)]);
        $executor = app(WorkflowAmoCrmActionExecutor::class);
        (new \ReflectionProperty($executor,'captureAmoExchange'))->setValue($executor,true);
        $request = new \ReflectionMethod($executor,'amoRequest');
        $account = (new Account)->forceFill(['access_token'=>'secret','subdomain'=>'example','zone'=>'ru']);
        $request->invoke($executor,$account,'POST','/api/v4/leads/42/notes',[['text'=>'Привет']]);
        $result = (new \ReflectionMethod($executor,'withAmoExchange'))->invoke($executor,['success'=>true],null);
        $exchange = $result['output']['amo_exchange'][0];
        $this->assertSame('POST',$exchange['request']['method']);
        $this->assertSame([['text'=>'Привет']],$exchange['request']['body']);
        $this->assertSame(201,$exchange['response']['code']);
        $this->assertSame(123,$exchange['response']['body']['id']);
        $this->assertSame('[Скрыто]',$exchange['response']['body']['access_token']);
        $this->assertStringNotContainsString('secret',json_encode($exchange));
        $this->assertNull($exchange['request']['query']);
    }
}
