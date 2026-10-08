<?php

namespace Tests\Unit\YClients;

use App\Models\amoCRM\Field;
use App\Models\Integrations\YClients\Setting;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Ufee\Amo\Base\Models\CustomField\MultiSelectField;
use Ufee\Amo\Models\CustomField;

class SettingRuntimeEnumsTest extends TestCase
{
    public function test_refresh_makes_new_enum_available_in_real_sdk_field(): void
    {
        $enums = (object)[101 => 'Existing'];
        $definition = new CustomField(['id' => 500, 'enums' => $enums]);
        $customField = new MultiSelectField([
            'id' => 500,
            'name' => 'Categories',
            'field' => $definition,
            'values' => [],
        ]);
        $field = new Field(['enums' => json_encode([
            ['id' => 101, 'value' => 'Existing'],
            ['id' => 102, 'value' => 'Agency "Astra"'],
        ])]);

        (new ReflectionMethod(Setting::class, 'refreshRuntimeEnums'))->invoke(null, $customField, $field);
        $customField->setValues(['Existing', 'Agency "Astra"']);

        $this->assertSame([101, 102], $customField->getEnums());
        $this->assertSame($enums, $definition->enums);
        $this->assertSame('Existing', $definition->enums->{101});
        $this->assertSame('Agency "Astra"', $definition->enums->{102});
        $this->assertSame([], $definition->changedFields());
    }

    public function test_refresh_preserves_runtime_enum_when_local_metadata_is_older(): void
    {
        $definition = new CustomField(['id' => 500, 'enums' => (object)[101 => 'Existing', 103 => 'Another worker']]);
        $customField = new MultiSelectField(['id' => 500, 'field' => $definition, 'values' => []]);
        $field = new Field(['enums' => json_encode([
            ['id' => 101, 'value' => 'Existing'],
            ['id' => 102, 'value' => 'New'],
        ])]);

        (new ReflectionMethod(Setting::class, 'refreshRuntimeEnums'))->invoke(null, $customField, $field);
        $customField->setValues(['New', 'Another worker']);

        $this->assertSame([102, 103], $customField->getEnums());
    }
}
