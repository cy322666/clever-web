<?php
namespace Tests\Unit\Workflows;

use App\Workflows\Actions\WorkflowHttpRequestAction;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkflowHttpRequestTest extends TestCase
{
    private function action(array $addresses = ['93.184.216.34']): WorkflowHttpRequestAction
    {
        return new class($addresses) extends WorkflowHttpRequestAction {
            public function __construct(private array $ips) {}
            protected function addresses(string $host): array { return $this->ips; }
        };
    }
    public function test_request_preserves_json_and_pins_public_dns_without_redirects(): void
    {
        Http::preventStrayRequests();
        $sentOptions = [];
        Http::fake(function ($request, $options) use (&$sentOptions) {
            $sentOptions = $options;
            return Http::response(['items'=>[['id'=>1]]],200);
        });
        $context = (new WorkflowContext)->setStepOutput('previous',['id'=>123]);
        $result = $this->action()->handle(['url'=>'https://public.example/{{ $json.id }}','method'=>'POST','headers'=>'{"X-Test":"ok"}','body'=>'{"id":123}'],$context);
        $this->assertTrue($result['success']);
        $this->assertSame(1,$result['output']['body']['items'][0]['id']);
        Http::assertSent(fn ($request) => $request->url()==='https://public.example/123'
            && $request['id']===123 && $request->hasHeader('X-Test','ok'));
        $this->assertFalse($sentOptions['allow_redirects']);
        $this->assertSame('',$sentOptions['proxy']);
        $this->assertSame(['public.example:443:93.184.216.34'],$sentOptions['curl'][CURLOPT_RESOLVE]);
    }
    public function test_private_mixed_dns_and_invalid_requests_never_leave_the_server(): void
    {
        Http::preventStrayRequests(); Http::fake();
        foreach ([['127.0.0.1'],['93.184.216.34','10.0.0.1'],['::1'],['::ffff:127.0.0.1'],['169.254.169.254'],[]] as $ips) {
            $this->assertFalse($this->action($ips)->handle(['url'=>'https://public.example'])['success']);
        }
        foreach ([['url'=>'file:///etc/passwd'],['url'=>'https://user:pass@public.example'],['url'=>'https://public.example','headers'=>'{"Host":"localhost"}'],['url'=>'https://public.example','body'=>'{broken'],['url'=>'https://public.example','timeout'=>31]] as $config) $this->assertFalse($this->action()->handle($config)['success']);
        Http::assertNothingSent();
    }
    public function test_json_variables_retain_types_and_quotes(): void
    {
        Http::fake(['*'=>Http::response(['ok'=>true])]);
        $context = (new WorkflowContext)->setStepOutput('previous',['id'=>123,'name'=>'ООО "Тест"','ids'=>[1,2]]);
        $result = $this->action()->handle(['url'=>'https://public.example','method'=>'POST','body'=>'{"id":"{{ $json.id }}","name":"{{ $json.name }}","ids":"{{ $json.ids }}"}'],$context);
        $this->assertTrue($result['success']);
        Http::assertSent(fn($request) => $request['id']===123 && $request['name']==='ООО "Тест"' && $request['ids']===[1,2]);
    }
    public function test_test_mode_does_not_send_and_large_or_failed_responses_fail(): void
    {
        Http::fake(['*'=>Http::sequence()->push(str_repeat('a',1048577))->push(['error'=>'bad'],400)]);
        $this->assertTrue($this->action()->handle(['url'=>'https://public.example'],(new WorkflowContext)->setTriggerSource('test'))['output']['dry_run']);
        Http::assertNothingSent();
        $this->assertFalse($this->action()->handle(['url'=>'https://public.example'])['success']);
        $this->assertFalse($this->action()->handle(['url'=>'https://public.example'])['success']);
    }
}
