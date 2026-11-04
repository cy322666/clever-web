<?php

namespace Tests\Unit\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Ufee\Amo\Base\Collections\Collection;
use Ufee\Amo\Base\Models\CustomField\TextField;
use Ufee\Amo\Base\Models\CustomField\TextareaField;
use Ufee\Amo\Base\Services\Service;
use Ufee\Amo\Models\EntityCustomFields;
use Ufee\Amo\Models\Lead;

class LeadServicesTextTest extends TestCase
{
    private Manager $database;
    private mixed $originalResolver;
    private mixed $originalDispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalResolver = Model::getConnectionResolver();
        $this->originalDispatcher = Model::getEventDispatcher();
        $this->database = new Manager();
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        Model::setConnectionResolver($this->database->getDatabaseManager());
        Model::unsetEventDispatcher();
        $this->database->getConnection()->getSchemaBuilder()->create('amocrm_fields', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('field_id');
            $table->string('type');
            $table->string('name');
            $table->string('entity_type');
            $table->integer('user_id');
        });
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        if ($this->originalResolver !== null) {
            Model::setConnectionResolver($this->originalResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        if ($this->originalDispatcher !== null) {
            Model::setEventDispatcher($this->originalDispatcher);
        } else {
            Model::unsetEventDispatcher();
        }
        parent::tearDown();
    }

    #[DataProvider('textValues')]
    public function test_services_text_is_safe_in_the_real_sdk_serialization(string $source, string $expected): void
    {
        $this->assertMappedValue('services', 'text', $source, $expected);
        $this->assertLessThanOrEqual(256, mb_strlen($expected, 'UTF-8'));
        $this->assertTrue(mb_check_encoding($expected, 'UTF-8'));
    }

    public static function textValues(): array
    {
        return [
            'exactly 256 characters' => [str_repeat('a', 256), str_repeat('a', 256)],
            '257 characters' => [str_repeat('a', 257), str_repeat('a', 253) . '...'],
            'Cyrillic characters, not bytes' => [str_repeat('Я', 257), str_repeat('Я', 253) . '...'],
            'multibyte character at cut boundary' => [str_repeat('Я', 252) . '🙂abcd', str_repeat('Я', 252) . '🙂...'],
            'line breaks and inner spaces' => [" \nПервая\r\n  Вторая\rТретья\n ", 'Первая   Вторая Третья'],
            'meaningful double spaces' => ['Первая  Вторая', 'Первая  Вторая'],
        ];
    }

    public function test_services_textarea_keeps_the_full_multiline_value(): void
    {
        $source = "\n  " . str_repeat('Услуга ', 80) . "\n  Следующая услуга  ";
        $this->assertMappedValue('services', 'textarea', $source, $source);
    }

    public function test_other_text_mapping_is_unchanged(): void
    {
        $source = "\n  " . str_repeat('Сотрудник ', 40) . "\n  ";
        $this->assertMappedValue('staff', 'text', $source, $source);
    }

    private function assertMappedValue(string $sourceField, string $type, string $source, string $expected): void
    {
        $this->database->getConnection()->table('amocrm_fields')->insert([
            'field_id' => 624255, 'type' => $type, 'name' => 'Fixture field', 'entity_type' => 'leads', 'user_id' => 17,
        ]);
        $fieldClass = $type === 'textarea' ? TextareaField::class : TextField::class;
        $field = new $fieldClass([
            'id' => 624255, 'account_id' => 1, 'name' => 'Fixture field', 'field_type' => $type === 'textarea' ? 9 : 1,
            'field' => (object)['is_system' => false], 'values' => [],
        ]);
        $fields = new Collection([$field]);
        $customFields = new EntityCustomFields($fields);
        $service = $this->createMock(Service::class);
        // Only the network save is mocked; field model, collection and serialization are the SDK's real types.
        $lead = $this->getMockBuilder(Lead::class)->setConstructorArgs([[], &$service])->onlyMethods(['save'])->getMock();
        $lead->expects($this->once())->method('save')->willReturn(true);
        $attributes = new ReflectionProperty($lead, 'attributes');
        $values = $attributes->getValue($lead);
        $values['customFields'] = $customFields;
        $attributes->setValue($lead, $values);
        $lead->updated_at = 1;
        $setting = new Setting(['user_id' => 17, 'fields_lead' => [['field_yc' => $sourceField, 'field_amo' => 624255]]]);
        $record = new Record(['title' => $source]);
        $ycFields = [$sourceField => $source];

        $this->assertSame($lead, $setting->YCSetLeadFields($lead, $ycFields));
        $this->assertInstanceOf($fieldClass, $lead->customFields->byId(624255));
        $wire = json_decode(json_encode($lead->getChangedRawApiData(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(624255, $wire['custom_fields'][0]['id']);
        $this->assertSame($expected, $wire['custom_fields'][0]['values'][0]['value']);
        $this->assertSame($source, $ycFields[$sourceField]);
        $this->assertSame($source, $record->title);
    }
}
