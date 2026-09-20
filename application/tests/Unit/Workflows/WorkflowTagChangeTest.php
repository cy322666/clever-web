<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Services\Workflows\WorkflowAmoCrmLoopGuard;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkflowTagChangeTest extends TestCase
{
    public function test_selective_tag_change_uses_the_native_add_and_delete_fields(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://tags.test/api/v4/leads/42' => Http::response(['id' => 42])]);

        $result = $this->change([
            'target_entity' => 'lead',
            'target_entity_id' => 42,
            'tags_to_add' => ['Новый', 'Оставить'],
            'tags_to_remove' => ['Старый', 'оставить'],
        ]);

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && $request->url() === 'https://tags.test/api/v4/leads/42'
                && $request->data() === [
                    'tags_to_add' => [['name' => 'Новый'], ['name' => 'Оставить']],
                    'tags_to_delete' => [['name' => 'Старый']],
                ];
        });
        Http::assertSentCount(1);
    }

    public function test_remove_all_sends_null_and_can_replace_with_new_tags(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://tags.test/*' => Http::response(['id' => 42])]);

        $this->change(['target_entity'=>'lead', 'target_entity_id'=>42, 'remove_all'=>true]);
        $this->change(['target_entity'=>'lead', 'target_entity_id'=>42, 'remove_all'=>true, 'tags_to_add'=>'Новый']);

        $bodies = Http::recorded()->map(fn(array $record) => $record[0]->data())->all();
        $this->assertSame(['_embedded'=>['tags'=>null]], $bodies[0]);
        $this->assertSame(['_embedded'=>['tags'=>[['name'=>'Новый']]]], $bodies[1]);
    }

    private function change(array $config): array
    {
        $executor = new WorkflowAmoCrmActionExecutor($this->createMock(WorkflowAmoCrmLoopGuard::class));
        $client = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
        $account = (new Account)->forceFill(['id'=>1, 'endpoint'=>'https://tags.test', 'access_token'=>'test-only']);

        return (new \ReflectionMethod($executor, 'changeTags'))->invoke($executor, $client, $account, $config, null);
    }
}
