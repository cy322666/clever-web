<?php

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowCredential;
use App\Services\Workflows\WorkflowCredentials;
use App\Services\Workflows\WorkflowDefinitionValidator;
use App\Services\Workflows\WorkflowTransfer;
use App\Workflows\Actions\TelegramSendMessageAction;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasFixture;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowCredentialsTest extends TestCase
{
    private const TOKEN = '12345:abcdefghijklmnopqrstuvwxyz012345';

    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        (require database_path('migrations/2026_09_14_210000_create_workflow_credentials_table.php'))->up();
        (require database_path('migrations/2023_09_01_120654_create_fields_table.php'))->up();
        \Illuminate\Support\Facades\Schema::table('amocrm_fields', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->boolean('active')->default(true));
        $this->actingAs(User::findOrFail(1));
        Http::preventStrayRequests();
    }

    public function test_editor_connection_encrypts_token_and_keeps_it_out_of_livewire_state(): void
    {
        $this->fakeBot();
        $page = Livewire::test(WorkflowCanvasFixture::class)->assertSee('Подключения')
            ->call('mountAction', 'workflowCredentials')
            ->assertSet('workflowCredentialProvider', 'telegram');
        $this->assertStringContainsString('Подключений пока нет', $this->credentialsModalHtml($page));
        $this->assertStringNotContainsString('Токен бота', $this->credentialsModalHtml($page));
        $page
            ->call('beginCreateWorkflowCredential')
            ->assertSet('workflowCredentialMode', 'create');
        $this->assertStringContainsString('Токен бота', $this->credentialsModalHtml($page));
        $this->assertStringNotContainsString('Название подключения', $this->credentialsModalHtml($page));
        $page->set('workflowCredentialToken', self::TOKEN)
            ->call('saveWorkflowCredential')
            ->assertHasNoErrors()
            ->assertSet('workflowCredentialMode', 'list')
            ->assertSet('workflowCredentialToken', '');
        $credential = WorkflowCredential::firstOrFail();
        $this->assertSame('@clever_test_bot', $credential->name);
        $this->assertSame(self::TOKEN, $credential->secret);
        $this->assertStringNotContainsString(self::TOKEN, DB::table('workflow_credentials')->value('secret'));
        $this->assertStringNotContainsString(self::TOKEN, json_encode($credential));
        $this->assertStringNotContainsString(self::TOKEN, $page->html());
        $this->assertStringNotContainsString(self::TOKEN, json_encode($page->get('mountedActions')));
        $page->call('mountAction', 'workflowCredentials')
            ->call('beginEditWorkflowCredential', $credential->id)
            ->assertSet('workflowCredentialMode', 'edit')
            ->assertSet('workflowCredentialToken', '')
            ->call('saveWorkflowCredential')
            ->assertHasNoErrors();
        $this->assertSame(self::TOKEN, $credential->fresh()->secret);
        $this->assertSame('@clever_test_bot', $credential->fresh()->name);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/getMe'));
    }

    public function test_connections_are_scoped_and_foreign_tokens_cannot_be_used(): void
    {
        $id = $this->saveBot();
        $this->actingAs(User::findOrFail(2));
        $this->assertSame([], WorkflowCredentials::options());
        $result = (new TelegramSendMessageAction)->handle(['credential_id' => $id, 'chat_id' => '123', 'text' => 'Тест'], (new WorkflowContext)->setTriggeredBy(2));
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('недоступно', $result['error']);
        Http::assertSentCount(1); // Only the owner's getMe during creation.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        WorkflowCredentials::save(['provider' => 'telegram', 'credential_id' => $id, 'credentials' => ['token' => self::TOKEN]]);
    }

    public function test_connection_manager_opens_with_the_list_and_can_delete_selected_connection(): void
    {
        $id = $this->saveBot();
        WorkflowCredential::create(['user_id' => 2, 'provider' => 'telegram', 'name' => '@foreign_bot', 'secret' => 'foreign']);

        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->call('mountAction', 'workflowCredentials')
            ->assertSet('workflowCredentialProvider', 'telegram');
        $html = $this->credentialsModalHtml($page);
        $this->assertStringContainsString('Сохранённые подключения', $html);
        $this->assertStringContainsString('@clever_test_bot', $html);
        $this->assertStringNotContainsString('@foreign_bot', $html);
        $this->assertStringContainsString('Добавить подключение', $html);
        $this->assertStringContainsString('logo/widgets/telegram.svg', $html);
        $this->assertStringNotContainsString('heroicon-o-paper-airplane', $html);
        $this->assertStringContainsString('aria-label="Изменить @clever_test_bot"', $html);
        $this->assertStringContainsString('aria-label="Удалить @clever_test_bot"', $html);

        $page->call('requestDeleteWorkflowCredential', $id)
            ->assertSet('workflowCredentialDeleteId', $id);
        $this->assertStringContainsString('Удалить?', $this->credentialsModalHtml($page));
        $page->call('deleteWorkflowCredential', $id)
            ->assertHasNoErrors();

        $this->assertNull(WorkflowCredential::find($id));
        $this->assertSame(1, WorkflowCredential::count());
    }

    public function test_worker_uses_workflow_owner_without_login_instead_of_event_initiator(): void
    {
        $id = $this->saveBot();
        $workflow = (new Workflow)->forceFill(['user_id' => 1, 'name' => 'Тест', 'trigger_type' => 'manual', 'is_active' => false, 'definition' => ['trigger' => ['type' => 'manual'], 'actions' => []]]);
        $workflow->saveQuietly();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);
        $result = (new TelegramSendMessageAction)->handle(['credential_id' => $id, 'chat_id' => '123', 'text' => 'Тест'], (new WorkflowContext)->setWorkflowId($workflow->id)->setTriggeredBy(2));
        $this->assertTrue($result['success'], $result['error'] ?? '');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage');
        $this->assertStringNotContainsString(self::TOKEN, json_encode($result));
    }

    public function test_selected_connection_validates_and_exports_without_credentials(): void
    {
        $id = $this->saveBot();
        $config = TelegramSendMessageAction::protectConfig(['credential_id' => $id, 'chat_id' => '123', 'text' => 'Тест'], ['bot_token_encrypted' => 'legacy']);
        $this->assertArrayNotHasKey('bot_token_encrypted', $config);
        $this->assertSame([], WorkflowDefinitionValidator::configIssues('telegram_send_message', $config));
        $json = WorkflowTransfer::encode('Тест', ['trigger' => ['type' => 'manual'], 'actions' => [['id' => 'tg', 'type' => 'telegram_send_message', 'config' => $config]]]);
        $this->assertStringNotContainsString('credential_id', $json);
        $this->assertStringNotContainsString(self::TOKEN, $json);
        $result = (new TelegramSendMessageAction)->handle($config, (new WorkflowContext)->setTriggeredBy(1)->setVariable('_dry_run', true));
        $this->assertTrue($result['output']['dry_run']);
        Http::assertSentCount(1); // Dry-run sends nothing after the connection check.
    }

    public function test_connection_icon_is_immediately_after_activation_without_visible_text(): void
    {
        $html = Livewire::test(WorkflowCanvasFixture::class)->html();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $buttons = $xpath->query('//button[@aria-label="Подключения"]');
        $this->assertCount(1, $buttons);
        $button = $buttons->item(0);
        $this->assertSame('', trim($button->textContent));
        $this->assertSame('toggleWorkflowActivation', $xpath->query('preceding-sibling::*[1]', $button)->item(0)->getAttribute('wire:click'));
        $this->assertSame(['telegram' => 'Telegram'], WorkflowCredentials::providers());
        $this->assertSame([], WorkflowCredentials::schema('amocrm'));
    }

    public function test_service_change_clears_secrets_and_unsupported_services_cannot_be_saved(): void
    {
        Livewire::test(WorkflowCanvasFixture::class)->call('mountAction', 'workflowCredentials')
            ->call('beginCreateWorkflowCredential')
            ->set('workflowCredentialToken', self::TOKEN)
            ->set('workflowCredentialProvider', 'amocrm')
            ->assertSet('workflowCredentialProvider', 'telegram')
            ->assertSet('workflowCredentialToken', '')
            ->assertSet('workflowCredentialId', null)
            ->assertDontSee('Токен бота');
        try {
            WorkflowCredentials::save(['provider' => 'amocrm', 'credentials' => ['token' => self::TOKEN]]);
            $this->fail('Unsupported service accepted');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('provider', $error->errors());
        }
        $this->assertSame(0, WorkflowCredential::count());
        Http::assertNothingSent();
    }

    public function test_invalid_tokens_show_field_errors_without_saving_or_leaking_response(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => self::TOKEN], 401)]);
        $page = Livewire::test(WorkflowCanvasFixture::class)->call('mountAction', 'workflowCredentials')
            ->call('beginCreateWorkflowCredential')
            ->set('workflowCredentialToken', self::TOKEN)
            ->call('saveWorkflowCredential')
            ->assertHasErrors(['workflowCredentialToken']);
        $this->assertStringContainsString('Telegram не подтвердил токен', $this->credentialsModalHtml($page));
        $this->assertSame(0, WorkflowCredential::count());
        $this->assertStringNotContainsString(self::TOKEN, json_encode($page->instance()->getErrorBag()->messages()));
        Http::assertSentCount(1);
    }

    public function test_failed_update_preserves_previous_token_and_name(): void
    {
        $id = $this->saveBot();
        Http::fake(fn () => throw new \RuntimeException('URL contains '.self::TOKEN));
        try {
            WorkflowCredentials::save(['provider' => 'telegram', 'credential_id' => $id, 'credentials' => ['token' => '12345:replacement']]);
            $this->fail('Failed check accepted');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('credentials.token', $error->errors());
            $this->assertStringNotContainsString(self::TOKEN, $error->getMessage());
        }
        $credential = WorkflowCredential::findOrFail($id);
        $this->assertSame(self::TOKEN, $credential->secret);
        $this->assertSame('@clever_test_bot', $credential->name);
    }

    public function test_token_update_refreshes_bot_label_without_changing_connection_id(): void
    {
        $id = $this->saveBot();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'is_bot' => true, 'username' => 'renamed_bot']])]);
        $saved = WorkflowCredentials::save(['provider' => 'telegram', 'credential_id' => $id, 'credentials' => ['token' => '12345:new-token']]);
        $this->assertSame($id, $saved);
        $this->assertSame(1, WorkflowCredential::count());
        $this->assertSame('12345:new-token', WorkflowCredential::findOrFail($id)->secret);
        $this->assertSame([$id => '@renamed_bot'], WorkflowCredentials::options('telegram'));
    }

    public function test_foreign_service_connection_is_not_accepted_by_telegram_runtime_or_editor(): void
    {
        $credential = WorkflowCredential::create(['user_id' => 1, 'provider' => 'another_service', 'name' => 'Other service', 'secret' => 'other']);
        $result = (new TelegramSendMessageAction)->handle(['credential_id' => $credential->id, 'chat_id' => '123', 'text' => 'Тест'], (new WorkflowContext)->setTriggeredBy(1));
        $this->assertFalse($result['success']);
        Http::assertNothingSent();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        WorkflowCredentials::save(['provider' => 'telegram', 'credential_id' => $credential->id, 'credentials' => ['token' => self::TOKEN]]);
    }

    public function test_telegram_node_lists_only_own_telegram_connections_and_saves_selection(): void
    {
        $id = $this->saveBot();
        WorkflowCredential::create(['user_id' => 2, 'provider' => 'telegram', 'name' => '@foreign_bot', 'secret' => 'foreign']);
        WorkflowCredential::create(['user_id' => 1, 'provider' => 'another_service', 'name' => 'Other service', 'secret' => 'other']);
        $page = $this->telegramNode();
        $html = $this->schemaHtml($page);
        $this->assertStringContainsString('@clever_test_bot', $html);
        $this->assertStringNotContainsString('@foreign_bot', $html);
        $this->assertStringNotContainsString('Other service', $html);
        $page->set('mountedActions.0.data.credential_id', $id)->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame($id, (int) $page->get('workflowActions')[0]['config']['credential_id']);
        $this->assertSame([$id => '@clever_test_bot'], WorkflowCredentials::options('telegram'));
    }

    public function test_new_connection_can_be_created_with_token_only_directly_in_node(): void
    {
        $this->fakeBot();
        $page = $this->telegramNode()
            ->call('mountAction', 'createOption', [], ['schemaComponent' => 'mountedActionSchema0.credential_id'])
            ->assertSet('mountedActions.1.name', 'createOption');
        $this->assertStringContainsString('Токен бота', $this->schemaHtml($page));
        $this->assertStringNotContainsString('Название подключения', $this->schemaHtml($page));
        $page->set('mountedActions.1.data.credentials.token', self::TOKEN)
            ->call('callMountedAction')->assertHasNoErrors();
        $credential = WorkflowCredential::firstOrFail();
        $page->assertSet('mountedActions.0.data.credential_id', $credential->id)
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame($credential->id, (int) $page->get('workflowActions')[0]['config']['credential_id']);
        $this->assertStringNotContainsString(self::TOKEN, $page->html());
        Http::assertSentCount(1);
    }

    private function fakeBot(): void
    {
        Http::fake(['api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 12345, 'is_bot' => true, 'username' => 'clever_test_bot']])]);
    }

    private function schemaHtml(\Livewire\Features\SupportTesting\Testable $page): string
    {
        // Livewire 4 delivers modal changes as partial effects, not the full component HTML.
        $previous = view()->shared('errors');
        view()->share('errors', (new \Illuminate\Support\ViewErrorBag)->put('default', $page->instance()->getErrorBag()));
        try {
            return $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
        } finally {
            view()->share('errors', $previous);
        }
    }

    private function credentialsModalHtml(\Livewire\Features\SupportTesting\Testable $page): string
    {
        $previous = view()->shared('errors');
        view()->share('errors', (new \Illuminate\Support\ViewErrorBag)->put('default', $page->instance()->getErrorBag()));
        try {
            return $page->instance()->getMountedAction()->getModalContent()->render();
        } finally {
            view()->share('errors', $previous);
        }
    }

    private function saveBot(): int
    {
        $this->fakeBot();

        return WorkflowCredentials::save(['provider' => 'telegram', 'credentials' => ['token' => self::TOKEN]]);
    }

    private function telegramNode(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [
            ['id' => 'tg', 'type' => 'telegram_send_message', 'config' => ['chat_id' => '123', 'text' => 'Тест']],
        ]])->call('openWorkflowActionEditor', 'tg');
    }
}
