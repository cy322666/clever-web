<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Actions\TelegramSendMessageAction;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowTelegramTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
    }

    public function test_a_send_is_encrypted_in_config_and_simulated_by_default(): void
    {
        $config = TelegramSendMessageAction::protectConfig(['bot_token' => '12345:test-token', 'chat_id' => '-1001', 'text' => 'Привет, {{ $json.name }}']);
        $this->assertArrayNotHasKey('bot_token', $config);
        $this->assertStringNotContainsString('test-token', json_encode($config));
        $this->assertSame('12345:test-token', Crypt::decryptString($config['bot_token_encrypted']));
        $context = (new WorkflowContext)->setTriggerData(['name' => 'Анна'])->setVariable('_dry_run', true);
        $result = (new TelegramSendMessageAction)->handle($config, $context);
        $this->assertSame('Привет, Анна', $result['output']['text']);
        $this->assertTrue($result['output']['dry_run']);
        Http::assertNothingSent();
    }

    public function test_real_mode_uses_send_message_and_never_exposes_the_token_in_errors(): void
    {
        Http::fake(['api.telegram.org/*' => Http::sequence()->push(['ok' => true, 'result' => ['message_id' => 7]])->push(['ok' => false, 'description' => 'token 12345:test-token'], 400)]);
        $config = TelegramSendMessageAction::protectConfig(['bot_token' => '12345:test-token', 'chat_id' => '-1001', 'text' => 'Тест', 'parse_mode' => 'HTML']);
        $result = (new TelegramSendMessageAction)->handle($config, new WorkflowContext);
        $this->assertSame(7, $result['output']['message_id']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/sendMessage') && $r['text'] === 'Тест' && $r['parse_mode'] === 'HTML');
        $result = (new TelegramSendMessageAction)->handle($config, new WorkflowContext);
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('test-token', $result['error']);
    }

    public function test_saving_the_form_preserves_a_hidden_token_when_only_text_changes(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [['id' => 'telegram', 'type' => 'telegram_send_message', 'config' => TelegramSendMessageAction::protectConfig(['bot_token' => '12345:test-token', 'chat_id' => '-1001', 'text' => 'Первое'])]]])->call('openWorkflowActionEditor', 'telegram');
        $before = $page->get('workflowActions')[0]['config']['bot_token_encrypted'];
        $page->set('mountedActions.0.data.text', 'Другое')->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame($before, $page->get('workflowActions')[0]['config']['bot_token_encrypted']);
        $this->assertArrayNotHasKey('bot_token', $page->get('workflowActions')[0]['config']);
    }
}
