<?php

namespace Tests\Unit\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\YClients;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Ufee\Amo\Base\Collections\Collection;
use Ufee\Amo\Base\Models\CustomField\TextField;
use Ufee\Amo\Base\Services\Service;
use Ufee\Amo\Models\EntityCustomFields;
use Ufee\Amo\Models\Lead;

class CreatedUserFailurePreservationTest extends TestCase
{
    private const ACTOR_METHODS = ['getUserPermissions', 'getUserRoles', 'getStaff', 'findStaffByUserId', 'findCompanyUserById', 'findPositionTitle'];

    #[DataProvider('actorFailures')]
    public function test_any_actor_http_failure_defers_all_three_actor_fields(string $method, int $status): void
    {
        $fields = Setting::YCGetFields($this->client($method, $status), $this->record());

        $this->assertNull($fields['created_user_name']);
        $this->assertNull($fields['created_user_role_name']);
        $this->assertNull($fields['created_user_department']);
        $this->assertSame('Known branch', $fields['branch']);
        $this->assertSame(777, $fields['record_id']);
        $this->assertSame('Current staff', $fields['staff']);
        $this->assertSame('Full services', $fields['services']);
        $this->assertSame(5000, $fields['paid']);
        $this->assertSame(0.0, $fields['record_paid']);
    }

    public static function actorFailures(): iterable
    {
        foreach (self::ACTOR_METHODS as $method) {
            foreach ([403, 404, 503] as $status) {
                yield "$method HTTP $status" => [$method, $status];
            }
        }
    }

    public function test_successful_actor_metadata_is_unchanged(): void
    {
        $this->assertKnownActor(Setting::YCGetFields($this->client(), $this->record()));
    }

    public function test_branch_failure_does_not_mark_actor_metadata_as_failed(): void
    {
        $fields = Setting::YCGetFields($this->client('getBranchTitle', 403), $this->record());
        $this->assertSame('10', $fields['branch']);
        $this->assertKnownActor($fields);
    }

    public function test_optional_record_failure_does_not_mark_successful_actor_metadata_as_failed(): void
    {
        $fields = Setting::YCGetFields($this->client('getRecord', 503), $this->record());
        $this->assertKnownActor($fields);
        $this->assertNull($fields['record_paid']);
        $this->assertSame(5000, $fields['paid']);
    }

    public function test_external_source_keeps_explicit_labels_without_actor_requests(): void
    {
        $fields = Setting::YCGetFields($this->client(external: true), $this->record(0));
        $this->assertSame('Не сотрудник', $fields['created_user_name']);
        $this->assertSame('Внешний источник', $fields['created_user_role_name']);
        $this->assertSame('Не сотрудник', $fields['created_user_department']);
    }

    public function test_successful_empty_actor_responses_keep_legacy_fallback_labels(): void
    {
        $fields = Setting::YCGetFields($this->client(emptyActor: true), $this->record());
        $this->assertSame('Пользователь YClients', $fields['created_user_name']);
        $this->assertSame('Сотрудник', $fields['created_user_role_name']);
        $this->assertSame('Сотрудник', $fields['created_user_department']);
    }

    public function test_core_client_error_still_propagates(): void
    {
        $this->expectException(RequestException::class);
        Setting::YCGetFields($this->client('getClient', 503), $this->record());
    }

    public function test_native_failure_result_preserves_existing_actor_values_in_real_sdk_payload(): void
    {
        $fields = Setting::YCGetFields($this->client('getUserRoles', 403), $this->record());
        $originalResolver = Model::getConnectionResolver();
        $originalDispatcher = Model::getEventDispatcher();
        $database = new Manager();
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        Model::setConnectionResolver($database->getDatabaseManager());
        Model::unsetEventDispatcher();
        try {
            $database->getConnection()->getSchemaBuilder()->create('amocrm_fields', function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('field_id');
                $table->string('type');
                $table->string('name');
                $table->string('entity_type');
                $table->integer('user_id');
            });
            $mapping = [];
            $sdkFields = [];
            $old = ['created_user_name' => 'Saved creator', 'created_user_role_name' => 'Saved role', 'created_user_department' => 'Saved department', 'staff' => 'Previous staff'];
            foreach (array_keys($old) as $offset => $source) {
                $id = 900 + $offset;
                $mapping[] = ['field_yc' => $source, 'field_amo' => $id];
                $database->getConnection()->table('amocrm_fields')->insert(['field_id' => $id, 'type' => 'text', 'name' => $source, 'entity_type' => 'leads', 'user_id' => 17]);
                $sdkFields[] = new TextField(['id' => $id, 'account_id' => 1, 'name' => $source, 'field_type' => 1, 'field' => (object)['is_system' => false], 'values' => [(object)['value' => $old[$source]]]]);
            }
            $collection = new Collection($sdkFields);
            $customFields = new EntityCustomFields($collection);
            $service = $this->createMock(Service::class);
            $lead = $this->getMockBuilder(Lead::class)->setConstructorArgs([[], &$service])->onlyMethods(['save'])->getMock();
            $lead->expects($this->once())->method('save')->willReturn(true);
            $attributes = new ReflectionProperty($lead, 'attributes');
            $values = $attributes->getValue($lead);
            $values['customFields'] = $customFields;
            $attributes->setValue($lead, $values);
            $lead->updated_at = 1;

            (new Setting(['user_id' => 17, 'fields_lead' => $mapping]))->YCSetLeadFields($lead, $fields);

            foreach (['created_user_name', 'created_user_role_name', 'created_user_department'] as $offset => $source) {
                $this->assertSame($old[$source], $lead->customFields->byId(900 + $offset)->getValue());
            }
            $this->assertSame('Current staff', $lead->customFields->byId(903)->getValue());
            $wire = json_decode(json_encode($lead->getChangedRawApiData(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([903], array_column($wire['custom_fields'], 'id'));
            $this->assertSame('Current staff', $wire['custom_fields'][0]['values'][0]['value']);
        } finally {
            $database->getConnection()->disconnect();
            $originalResolver !== null ? Model::setConnectionResolver($originalResolver) : Model::unsetConnectionResolver();
            $originalDispatcher !== null ? Model::setEventDispatcher($originalDispatcher) : Model::unsetEventDispatcher();
        }
    }

    private function assertKnownActor(array $fields): void
    {
        $this->assertSame('Known actor', $fields['created_user_name']);
        $this->assertSame('Сотрудник', $fields['created_user_role_name']);
        $this->assertSame('Known department', $fields['created_user_department']);
    }

    private function record(int $actor = 55): Record
    {
        return new Record(['company_id' => 10, 'record_id' => 777, 'client_id' => 555, 'created_user_id' => $actor, 'record_from' => 'CRM', 'staff_name' => 'Current staff', 'title' => 'Full services', 'cost' => 100]);
    }

    private function client(?string $failed = null, int $status = 403, bool $external = false, bool $emptyActor = false): YClients
    {
        $methods = array_merge(['getClient', 'getRecord', 'getBranchTitle'], self::ACTOR_METHODS);
        $client = $this->getMockBuilder(YClients::class)->disableOriginalConstructor()->onlyMethods($methods)->getMock();
        $staff = (object)['data' => (object)['name' => 'Known actor', 'position' => (object)['title' => 'Known department']]];
        $withoutDirectStaff = in_array($failed, ['findStaffByUserId', 'findCompanyUserById', 'findPositionTitle'], true);
        $values = [
            'getClient' => (object)['data' => (object)['paid' => 5000]],
            'getRecord' => (object)['data' => (object)['id' => 777, 'created_user_id' => $external ? 0 : 55, 'finance_transactions' => []]],
            'getBranchTitle' => 'Known branch',
            'getUserPermissions' => (object)['data' => (object)['staff_id' => $withoutDirectStaff ? 0 : 3, 'user_role' => 'worker', 'user_permissions' => [(object)['slug' => 'timetable_position_id', 'value' => 99]]]],
            'getUserRoles' => (object)['data' => []],
            'getStaff' => $staff,
            'findStaffByUserId' => in_array($failed, ['findCompanyUserById', 'findPositionTitle'], true) ? null : $staff,
            'findCompanyUserById' => (object)['name' => 'Known actor'],
            'findPositionTitle' => 'Known department',
        ];
        foreach ($methods as $method) {
            if ($external && in_array($method, self::ACTOR_METHODS, true)) {
                $client->expects($this->never())->method($method);
            } elseif ($method === $failed) {
                $exception = new RequestException(new Response(new PsrResponse($status, [], '{"success":false}')));
                $client->expects($this->atLeastOnce())->method($method)->willThrowException($exception);
            } else {
                $client->method($method)->willReturn($emptyActor && in_array($method, self::ACTOR_METHODS, true) ? null : $values[$method]);
            }
        }
        return $client;
    }
}
