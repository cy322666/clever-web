<?php

namespace Tests\Unit\Distribution;

use App\Http\Controllers\Api\DistributionController;
use ReflectionMethod;
use Tests\TestCase;

class DistributionDigitalPipelineTest extends TestCase
{
    public function test_widget_exposes_distribution_template_selector_in_digital_pipeline(): void
    {
        $manifest = json_decode(
            file_get_contents(public_path('amocrm/distribution/manifest.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $translations = json_decode(
            file_get_contents(public_path('amocrm/distribution/i18n/ru.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $script = file_get_contents(public_path('amocrm/distribution/script.js'));

        $this->assertContains('digital_pipeline', $manifest['locations']);
        $this->assertSame(
            'https://app.clevercrm.pro/api/amocrm/distribution/digital-pipeline',
            $manifest['dp']['webhook_url'],
        );
        $this->assertTrue($manifest['dp']['settings']['queue_uuid']['required']);
        $this->assertSame('Шаблон распределения', $translations['dp']['queue_uuid']);
        $this->assertStringContainsString('callbacks = {', $script);
        $this->assertStringContainsString('dpSettings: function ()', $script);
        $this->assertStringContainsString('response.templates || response.queues', $script);
    }

    public function test_official_amo_crm_digital_pipeline_payload_resolves_lead_and_template(): void
    {
        $payload = [
            'event' => [
                'type' => 14,
                'type_code' => 'lead_status_changed',
                'data' => [
                    'id' => 28020141,
                    'element_type' => 2,
                    'status_id' => 74275450,
                    'pipeline_id' => 92532,
                ],
            ],
            'action' => [
                'settings' => [
                    'widget' => [
                        'settings' => [
                            'queue_uuid' => '9d613971-6f16-45b8-9286-96ce791f75bc',
                        ],
                    ],
                ],
            ],
            'subdomain' => 'example',
            'account_id' => 123456,
        ];

        $controller = new DistributionController();

        $this->assertSame(
            28020141,
            (new ReflectionMethod($controller, 'resolveLeadId'))->invoke($controller, $payload),
        );
        $this->assertSame(
            '9d613971-6f16-45b8-9286-96ce791f75bc',
            (new ReflectionMethod($controller, 'extractQueue'))->invoke($controller, $payload),
        );
    }

    public function test_template_list_uses_saved_names_and_stable_ids(): void
    {
        $controller = new DistributionController();
        $templates = (new ReflectionMethod($controller, 'distributionQueues'))->invoke(
            $controller,
            json_encode([
                [
                    'queue_uuid' => 'main-template',
                    'name' => 'Основное распределение',
                ],
                [
                    'queue_uuid' => 'repeat-template',
                    'name' => 'Повторные продажи',
                ],
            ], JSON_UNESCAPED_UNICODE),
        );

        $this->assertSame([
            ['id' => 'main-template', 'name' => 'Основное распределение'],
            ['id' => 'repeat-template', 'name' => 'Повторные продажи'],
        ], $templates);
    }
}
