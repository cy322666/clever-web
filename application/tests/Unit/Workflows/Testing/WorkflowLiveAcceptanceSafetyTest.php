<?php

namespace Tests\Unit\Workflows\Testing;

use App\Services\Workflows\Testing\WorkflowLiveAcceptance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** These guard tests deliberately do not boot Laravel or send any HTTP request. */
final class WorkflowLiveAcceptanceSafetyTest extends TestCase
{
    #[DataProvider('allowedMutations')]
    public function test_only_expected_suite_mutations_are_allowed(string $method, string $path, array $body): void
    {
        WorkflowLiveAcceptance::assertSafeMutation($method, $path, $body);
        $this->addToAssertionCount(1);
    }

    public static function allowedMutations(): array
    {
        return [
            'closed lead' => ['POST', '/api/v4/leads', [['name'=>'Clever QA','status_id'=>143]]],
            'existing contact link embedded' => ['POST', '/api/v4/leads', [['status_id'=>143,'_embedded'=>['contacts'=>[['id'=>42]],'companies'=>[['id'=>43]]]]]],
            'task on existing lead' => ['POST', '/api/v4/tasks', [['entity_id'=>123,'entity_type'=>'leads','text'=>'QA']]],
            'lead closure' => ['PATCH', '/api/v4/leads/123', ['status_id'=>143]],
            'restore existing contact' => ['PATCH', '/api/v4/contacts/42', ['name'=>'Original name']],
            'update existing company' => ['PATCH', '/api/v4/companies/43', ['name'=>'QA']],
            'complete task' => ['PATCH', '/api/v4/tasks/55', ['is_completed'=>true]],
            'lead note' => ['POST', '/api/v4/leads/123/notes', [['note_type'=>'common','params'=>['text'=>'QA']]]],
            'contact note' => ['POST', '/api/v4/contacts/42/notes', [['note_type'=>'common','params'=>['text'=>'QA']]]],
            'link existing contact' => ['POST', '/api/v4/leads/123/link', [['to_entity_id'=>42,'to_entity_type'=>'contacts']]],
            'unlink existing contact' => ['POST', '/api/v4/leads/123/unlink', [['to_entity_id'=>42,'to_entity_type'=>'contacts']]],
            'install observer hook' => ['POST', '/api/v4/webhooks', ['destination'=>'https://example.test/hook','settings'=>['add_lead']]],
            'remove observer hook' => ['DELETE', '/api/v4/webhooks', ['destination'=>'https://example.test/hook']],
        ];
    }

    #[DataProvider('forbiddenMutations')]
    public function test_creating_restricted_entities_and_unlisted_mutations_are_blocked(string $method, string $path, array $body): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Acceptance guard:');
        WorkflowLiveAcceptance::assertSafeMutation($method, $path, $body);
    }

    public static function forbiddenMutations(): array
    {
        return [
            'create contact' => ['POST', '/api/v4/contacts', [['name'=>'Forbidden']]],
            'create company' => ['POST', '/api/v4/companies', [['name'=>'Forbidden']]],
            'create customer' => ['POST', '/api/v4/customers', [['name'=>'Forbidden']]],
            'create contact under extra path' => ['POST', '/api/v4/contacts/complex', [['name'=>'Forbidden']]],
            'complex lead creation' => ['POST', '/api/v4/leads/complex', [['name'=>'Forbidden']]],
            'embedded new contact' => ['POST', '/api/v4/leads', [['_embedded'=>['contacts'=>[['name'=>'Forbidden']]]]]],
            'embedded new company on update' => ['PATCH', '/api/v4/leads/123', ['_embedded'=>['companies'=>[['name'=>'Forbidden']]]]],
            'embedded customer' => ['PATCH', '/api/v4/contacts/42', ['_embedded'=>['customers'=>[['name'=>'Forbidden']]]]],
            'nested embedded contact' => ['POST', '/api/v4/leads', [['nested'=>['_embedded'=>['contacts'=>[['name'=>'Forbidden']]]]]]],
            'mixed existing and new' => ['POST', '/api/v4/leads', [['_embedded'=>['contacts'=>[['id'=>42],['name'=>'Forbidden']]]]]],
            'zero contact ID' => ['POST', '/api/v4/leads', [['_embedded'=>['contacts'=>[['id'=>0]]]]]],
            'null contact ID' => ['POST', '/api/v4/leads', [['_embedded'=>['contacts'=>[['id'=>null]]]]]],
            'empty contact ID' => ['POST', '/api/v4/leads', [['_embedded'=>['contacts'=>[['id'=>'']]]]]],
            'scalar contact record' => ['POST', '/api/v4/leads', [['_embedded'=>['contacts'=>['Forbidden']]]]],
            'delete lead' => ['DELETE', '/api/v4/leads/123', []],
            'delete contact' => ['DELETE', '/api/v4/contacts/42', []],
            'delete company' => ['DELETE', '/api/v4/companies/43', []],
            'bulk contact update' => ['PATCH', '/api/v4/contacts', [['id'=>42,'name'=>'Forbidden']]],
            'arbitrary bot' => ['POST', '/api/v2/salesbot/run', [['bot_id'=>123]]],
            'pipeline modification' => ['PATCH', '/api/v4/leads/pipelines/123', ['name'=>'Forbidden']],
            'field creation' => ['POST', '/api/v4/contacts/custom_fields', [['name'=>'Forbidden']]],
            'trailing slash mismatch' => ['POST', '/api/v4/leads/', []],
            'traversal path' => ['POST', '/api/v4/leads/../contacts', []],
            'encoded path' => ['POST', '/api/v4/%63ontacts', []],
            'unexpected method' => ['PUT', '/api/v4/leads/123', ['name'=>'Forbidden']],
            'oauth mutation' => ['POST', '/oauth2/access_token', ['grant_type'=>'refresh_token']],
        ];
    }
}
