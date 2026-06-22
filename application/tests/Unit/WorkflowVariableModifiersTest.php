<?php

namespace Tests\Unit;

use App\Workflows\Context\WorkflowContext;
use Tests\TestCase;

class WorkflowVariableModifiersTest extends TestCase
{
    public function test_it_resolves_variable_modifiers(): void
    {
        $context = new WorkflowContext([
            'lead' => [
                'name' => '  Тест  ',
                'price' => 15000,
                'created_at' => '2026-06-19 10:20:00',
                'tags' => ['vip', 'new'],
            ],
            'contact' => [
                'phone' => '8 (999) 123-45-67',
            ],
            'payload' => [
                'client' => [
                    'phone' => '+7 999 111 22 33',
                ],
            ],
        ]);

        $this->assertSame('ТЕСТ', $context->resolve('{{lead.name:trim:upper}}'));
        $this->assertSame('15 000', $context->resolve('{{lead.price:number(0)}}'));
        $this->assertSame('19.06.2026 10:20', $context->resolve('{{lead.created_at:date(d.m.Y H:i)}}'));
        $this->assertSame('79991234567', $context->resolve('{{contact.phone:phone_ru}}'));
        $this->assertSame('79991112233', $context->resolve('{{payload.client.phone:digits}}'));
        $this->assertSame('vip, new', $context->resolve('{{lead.tags:join(, )}}'));
        $this->assertSame('Без названия', $context->resolve('{{missing.value:default(Без названия)}}'));
        $this->assertSame('20.06.2026', $context->resolve('{{lead.created_at:add(1 day):date(d.m.Y)}}'));
        $this->assertSame('19.06.2026 09:50', $context->resolve('{{lead.created_at:sub(30 minutes):datetime(d.m.Y H:i)}}'));
        $this->assertSame('{"client":{"phone":"+7 999 111 22 33"}}', $context->resolve('{{payload:json}}'));
    }
}
