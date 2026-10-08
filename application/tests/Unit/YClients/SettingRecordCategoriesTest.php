<?php

namespace Tests\Unit\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Models\amoCRM\Field;
use App\Services\YClients\YClients;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SettingRecordCategoriesTest extends TestCase
{
    public function test_record_categories_are_read_from_labels_not_client_categories(): void
    {
        $fields = Setting::YCGetRecordCategoryFields([
            'record_labels' => [
                ['id' => 10, 'title' => ' Call center '],
                (object)['id' => 11, 'title' => 'Partner, referral'],
                ['id' => 12, 'title' => 'Call center'],
                ['id' => 13, 'title' => ' '],
                ['id' => 14],
            ],
            'client' => ['categories' => [['title' => 'Client only']]],
        ]);

        $this->assertSame('Call center, Partner, referral', $fields['record_categories']);
        $this->assertSame(['Call center', 'Partner, referral'], $fields['record_categories_values']);
    }

    public function test_empty_record_labels_do_not_fall_back_to_client_categories(): void
    {
        foreach ([null, [], ['client' => ['categories' => ['Client only']]], [
            'record_labels' => [],
            'client' => ['client_tags' => [['title' => 'Client only']]],
        ]] as $record) {
            $this->assertSame([
                'record_categories' => null,
                'record_categories_values' => [],
            ], Setting::YCGetRecordCategoryFields($record));
        }
    }

    public function test_regular_export_reads_record_categories_without_a_client(): void
    {
        $yc = $this->getMockBuilder(YClients::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getClient', 'getRecord', 'getBranchTitle'])
            ->getMock();
        $yc->expects($this->never())->method('getClient');
        $yc->expects($this->once())->method('getRecord')->with(10, 777)->willReturn((object)[
            'data' => (object)[
                'record_labels' => [(object)['id' => 1, 'title' => 'Call center']],
            ],
        ]);
        $yc->method('getBranchTitle')->willReturn('Branch');

        $fields = Setting::YCGetFields($yc, new Record([
            'company_id' => 10,
            'record_id' => 777,
            'datetime' => '2026-10-01 14:15:00',
        ]));

        $this->assertSame('Call center', $fields['record_categories']);
        $this->assertSame(['Call center'], $fields['record_categories_values']);
        $this->assertNull($fields['categories']);
        $this->assertSame([], $fields['categories_values']);
    }

    public function test_record_categories_use_individual_values_for_multiselect(): void
    {
        $fields = Setting::YCGetRecordCategoryFields((object)[
            'record_labels' => [
                (object)['title' => 'Call center'],
                (object)['title' => 'Partner, referral'],
            ],
        ]);
        $fields['categories_values'] = ['Client only'];
        $method = new ReflectionMethod(Setting::class, 'valueForAmoField');

        $value = $method->invoke(null, 'record_categories', new Field(['type' => 'multiselect']),
            $fields['record_categories'], $fields);

        $this->assertSame(['Call center', 'Partner, referral'], $value);
        $this->assertSame(['Client only'], $method->invoke(null, 'categories',
            new Field(['type' => 'multiselect']), 'Client only', $fields));

        $customField = new class {
            public array $values = ['Old category'];

            public function reset(): void
            {
                $this->values = [];
            }

            public function setValues(array $values): void
            {
                $this->values = array_merge($this->values, $values);
            }
        };
        (new ReflectionMethod(Setting::class, 'setCustomFieldValue'))->invoke(null, $customField, $value);
        $this->assertSame(['Call center', 'Partner, referral'], $customField->values);
    }

    public function test_record_categories_use_joined_titles_for_text_field(): void
    {
        $fields = Setting::YCGetRecordCategoryFields([
            'record_labels' => [['title' => 'First'], ['title' => 'Second']],
        ]);
        $value = (new ReflectionMethod(Setting::class, 'valueForAmoField'))->invoke(null,
            'record_categories', new Field(['type' => 'text']), $fields['record_categories'], $fields);

        $this->assertSame('First, Second', $value);
    }
}
