<?php

namespace Tests\Unit\Workflows;

use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowGenericWebhookService;
use App\Workflows\Triggers\GenericWebhookTrigger;
use Illuminate\Support\Carbon;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Tests\TestCase;

class WorkflowGenericWebhookServiceTest extends TestCase
{
    public function test_it_builds_signed_callback_url_and_validates_signature(): void
    {
        config(['app.key' => 'base64:' . base64_encode('workflow-test-key')]);

        $workflow = new Workflow([
            'user_id' => 45,
            'is_active' => true,
            'definition' => [
                'trigger' => [
                    'type' => GenericWebhookTrigger::type(),
                ],
            ],
        ]);
        $workflow->id = 123;
        $workflow->created_at = Carbon::parse('2026-06-14 10:00:00');

        $service = new WorkflowGenericWebhookService($this->createMock(WorkflowExecutor::class));
        $url = $service->callbackUrl($workflow);
        $signature = basename(parse_url($url, PHP_URL_PATH));

        $this->assertStringContainsString('/workflows/webhook/123/', $url);
        $this->assertTrue($service->signatureIsValid($workflow, $signature));
        $this->assertFalse($service->signatureIsValid($workflow, 'bad-signature'));
    }

    public function test_it_receives_only_active_generic_webhook_workflows(): void
    {
        $service = new WorkflowGenericWebhookService($this->createMock(WorkflowExecutor::class));

        $active = new Workflow([
            'is_active' => true,
            'definition' => [
                'trigger' => [
                    'type' => GenericWebhookTrigger::type(),
                ],
            ],
        ]);

        $inactive = new Workflow([
            'is_active' => false,
            'definition' => [
                'trigger' => [
                    'type' => GenericWebhookTrigger::type(),
                ],
            ],
        ]);

        $manual = new Workflow([
            'is_active' => true,
            'definition' => [
                'trigger' => [
                    'type' => 'manual',
                ],
            ],
        ]);

        $this->assertTrue($service->canReceive($active));
        $this->assertFalse($service->canReceive($inactive));
        $this->assertFalse($service->canReceive($manual));
    }
}
