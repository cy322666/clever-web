<?php

namespace Tests\Unit\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\YClients as YClientsService;
use PHPUnit\Framework\TestCase;

class SettingFieldsTest extends TestCase
{
    public function test_yc_mapping_rows_handles_empty_or_invalid_mapping(): void
    {
        $method = new \ReflectionMethod(Setting::class, 'mappingRows');

        $this->assertSame([], $method->invoke(null, null));
        $this->assertSame([], $method->invoke(null, ''));
        $this->assertSame([], $method->invoke(null, 'not-json'));
        $this->assertSame([], $method->invoke(null, '[{"field_yc":null,"field_amo":null}]'));
        $this->assertSame(
            [['field_yc' => 'services', 'field_amo' => 123]],
            $method->invoke(null, '[{"field_yc":"services","field_amo":123}]')
        );
    }

    public function test_yc_fields_select_shows_system_keys_in_labels(): void
    {
        $fields = Setting::YCfieldsSelect();

        $this->assertSame('Запись', $fields['record_id']);
        $this->assertSame('Дата и время записи', $fields['record_datetime']);
        $this->assertSame('Дата записи', $fields['record_date']);
        $this->assertSame('Время записи', $fields['record_time']);
        $this->assertSame('Источник записи', $fields['record_from']);
        $this->assertSame('Роль создателя', $fields['created_user_role_name']);
        $this->assertSame('Отдел создателя', $fields['created_user_department']);
        $this->assertSame('Филиал записи', $fields['company_id']);
        $this->assertSame('Услуги (services)', $fields['services']);
        $this->assertSame('Пол (sex) - список М/Ж/строка', $fields['sex']);
        $this->assertSame('Сумма покупок (paid)', $fields['paid']);
    }

    public function test_yc_get_fields_includes_record_and_company_ids(): void
    {
        $yc = $this->getMockBuilder(YClientsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getClient', 'getRecord', 'getBranchTitle', 'getUserPermissions', 'getUserRoles', 'getStaff'])
            ->getMock();

        $yc->method('getClient')->willReturn(
            (object)[
                'data' => (object)[
                    'sex' => 'Женский',
                    'birth_date' => '1990-01-01',
                    'visits' => 12,
                    'paid' => 3456,
                    'created_user_id' => 4321,
                    'discount' => 10,
                    'comment' => 'Карта пациента',
                    'sms_check' => 1,
                    'sms_not' => 0,
                ],
            ]
        );

        $yc->method('getBranchTitle')->willReturn('Филиал 10');
        $yc->method('getRecord')->willReturn((object)[
            'data' => (object)[
                'created_user_id' => 4321,
                'record_from' => 'CRM',
            ],
        ]);
        $yc->method('getUserPermissions')->willReturn(
            (object)[
                'data' => (object)[
                    'staff_id' => 43210,
                    'user_role' => 'worker',
                ],
            ]
        );
        $yc->method('getUserRoles')->willReturn(
            (object)[
                'data' => [
                    (object)[
                        'slug' => 'staff_member',
                        'title' => 'team member',
                    ],
                ],
            ]
        );
        $yc->method('getStaff')->willReturn(
            (object)[
                'data' => (object)[
                    'position' => (object)[
                        'id' => 77,
                        'title' => 'Администратор',
                    ],
                ],
            ]
        );

        $record = new Record([
            'company_id' => 10,
            'client_id' => 555,
            'record_id' => 777,
            'datetime' => '2026.05.20 15:00:00',
            'record_from' => 'CRM',
            'created_user_id' => 4321,
            'staff_name' => 'Мастер',
            'title' => "\n   Консультация\n   Чистка",
        ]);

        $fields = Setting::YCGetFields($yc, $record);

        $this->assertSame(10, $fields['company_id']);
        $this->assertSame(777, $fields['record_id']);
        $this->assertSame('20.05.2026 15:00', $fields['record_datetime']);
        $this->assertSame('20.05.2026', $fields['record_date']);
        $this->assertSame('15:00', $fields['record_time']);
        $this->assertSame('CRM', $fields['record_from']);
        $this->assertSame('Сотрудник', $fields['created_user_role_name']);
        $this->assertSame('Администратор', $fields['created_user_department']);
        $this->assertSame('Филиал 10', $fields['branch']);
        $this->assertSame('Ж', $fields['sex']);
        $this->assertSame('1990-01-01', $fields['birth_date']);
        $this->assertSame(12, $fields['visits']);
        $this->assertSame("Консультация\n   Чистка", $fields['services']);
        $this->assertSame('Мастер', $fields['staff']);
        $this->assertSame(3456, $fields['paid']);
        $this->assertSame(3456, $fields['ltv']);
        $this->assertSame(555, $fields['client_id']);
        $this->assertSame(10, $fields['discount']);
        $this->assertSame('Карта пациента', $fields['comment']);
        $this->assertSame('Да', $fields['sms_check']);
        $this->assertSame('Да', $fields['sms_not']);
    }

    public function test_yc_get_fields_resolves_created_user_role_and_department_from_permissions(): void
    {
        $yc = $this->getMockBuilder(YClientsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getClient',
                'getRecord',
                'getBranchTitle',
                'getUserPermissions',
                'getUserRoles',
                'getStaff',
                'findStaffByUserId',
                'findPositionTitle',
            ])
            ->getMock();

        $yc->method('getClient')->willReturn(
            (object)[
                'data' => (object)[
                    'sex' => null,
                    'birth_date' => null,
                    'visits' => 1,
                    'paid' => 0,
                ],
            ]
        );
        $yc->method('getBranchTitle')->willReturn('Филиал');
        $yc->method('getRecord')->willReturn((object)[
            'data' => (object)[
                'created_user_id' => 4321,
            ],
        ]);
        $yc->method('getUserPermissions')->willReturn(
            (object)[
                'data' => (object)[
                    'staff_id' => 0,
                    'user_role' => 'manager',
                    'user_permissions' => [
                        (object)[
                            'slug' => 'timetable_position_id',
                            'value' => 99,
                        ],
                    ],
                ],
            ]
        );
        $yc->method('getUserRoles')->willReturn((object)['data' => []]);
        $yc->method('getStaff')->willReturn(null);
        $yc->method('findStaffByUserId')->willReturn(null);
        $yc->method('findPositionTitle')->with('10', 99)->willReturn('Администраторы');

        $record = new Record([
            'company_id' => 10,
            'client_id' => 555,
            'record_id' => 777,
            'created_user_id' => 4321,
            'staff_name' => 'Мастер',
        ]);

        $fields = Setting::YCGetFields($yc, $record);

        $this->assertSame('Менеджер', $fields['created_user_role_name']);
        $this->assertSame('Администраторы', $fields['created_user_department']);
    }

    public function test_yc_get_fields_uses_staff_specialization_when_permissions_are_denied(): void
    {
        $yc = $this->getMockBuilder(YClientsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getClient',
                'getRecord',
                'getBranchTitle',
                'getUserPermissions',
                'getUserRoles',
                'getStaff',
                'findStaffByUserId',
                'findPositionTitle',
            ])
            ->getMock();

        $yc->method('getClient')->willReturn(
            (object)[
                'data' => (object)[
                    'sex' => null,
                    'birth_date' => null,
                    'visits' => 7,
                    'paid' => 0,
                ],
            ]
        );
        $yc->method('getBranchTitle')->willReturn('Филиал');
        $yc->method('getRecord')->willReturn((object)[
            'data' => (object)[
                'created_user_id' => 12348716,
            ],
        ]);
        $yc->method('getUserPermissions')->willReturn(
            (object)[
                'success' => false,
                'data' => null,
                'meta' => (object)['message' => 'Недостаточно прав'],
            ]
        );
        $yc->method('getUserRoles')->willReturn(
            (object)[
                'success' => false,
                'data' => null,
                'meta' => (object)['message' => 'Недостаточно прав'],
            ]
        );
        $yc->method('getStaff')->willReturn(null);
        $yc->method('findStaffByUserId')->willReturn(
            (object)[
                'id' => 5297304,
                'user_id' => 12348716,
                'name' => 'Гурова Дарья Александровна',
                'specialization' => 'Колл-центр',
                'position' => null,
            ]
        );
        $yc->method('findPositionTitle')->willReturn(null);

        $record = new Record([
            'company_id' => 1114763,
            'client_id' => 393190842,
            'record_id' => 1722239055,
            'created_user_id' => 12348716,
            'staff_name' => 'ПАРКИНГ 53',
        ]);

        $fields = Setting::YCGetFields($yc, $record);

        $this->assertSame('Сотрудник', $fields['created_user_role_name']);
        $this->assertSame('Колл-центр', $fields['created_user_department']);
    }

    public function test_yc_get_fields_marks_online_records_without_created_user(): void
    {
        $yc = $this->getMockBuilder(YClientsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getClient', 'getRecord', 'getBranchTitle'])
            ->getMock();

        $yc->method('getClient')->willReturn(
            (object)[
                'data' => (object)[
                    'sex' => 'Неизвестно',
                    'birth_date' => '1997-09-22',
                    'visits' => 30,
                    'paid' => 101333,
                ],
            ]
        );
        $yc->method('getBranchTitle')->willReturn('Филиал');
        $yc->method('getRecord')->willReturn((object)[
            'data' => (object)[
                'created_user_id' => 0,
                'record_from' => 'Партнёры: Mobile app new widget',
            ],
        ]);

        $record = new Record([
            'company_id' => 331981,
            'client_id' => 162146132,
            'record_id' => 1722699114,
            'datetime' => '2026.05.20 17:30:00',
            'record_from' => 'Партнёры: Mobile app new widget',
            'created_user_id' => 0,
            'staff_name' => 'Бурда Наталья Александровна',
        ]);

        $fields = Setting::YCGetFields($yc, $record);

        $this->assertSame('Внешний источник', $fields['created_user_role_name']);
        $this->assertSame('Не сотрудник', $fields['created_user_department']);
        $this->assertSame('Партнёры: Mobile app new widget', $fields['record_from']);
    }

    public function test_yc_get_fields_does_not_fail_when_client_data_is_empty(): void
    {
        $yc = $this->getMockBuilder(YClientsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getClient', 'getRecord', 'getBranchTitle'])
            ->getMock();

        $yc->method('getClient')->willReturn((object)['data' => null]);
        $yc->method('getBranchTitle')->willReturn('Филиал');
        $yc->method('getRecord')->willReturn(
            (object)[
                'data' => (object)[
                    'created_user_id' => 0,
                    'record_from' => 'Онлайн',
                ],
            ]
        );

        $record = new Record([
            'company_id' => 331981,
            'client_id' => 162146132,
            'record_id' => 1722699114,
            'datetime' => '2026.05.20 17:30:00',
            'title' => "\n   Консультация",
            'staff_name' => 'Специалист',
        ]);

        $fields = Setting::YCGetFields($yc, $record);

        $this->assertSame(1722699114, $fields['record_id']);
        $this->assertSame('20.05.2026 17:30', $fields['record_datetime']);
        $this->assertSame('Консультация', $fields['services']);
        $this->assertSame('Онлайн', $fields['record_from']);
        $this->assertNull($fields['sex']);
        $this->assertNull($fields['paid']);
        $this->assertNull($fields['visits']);
    }

    public function test_yc_get_fields_fetches_record_from_for_existing_records(): void
    {
        $yc = $this->getMockBuilder(YClientsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getClient', 'getRecord', 'getBranchTitle'])
            ->getMock();

        $yc->method('getClient')->willReturn((object)[
            'data' => (object)[
                'sex' => null,
                'birth_date' => null,
                'visits' => 1,
                'paid' => 0,
            ],
        ]);
        $yc->method('getBranchTitle')->willReturn('Филиал');
        $yc->method('getRecord')->willReturn((object)[
            'data' => (object)[
                'created_user_id' => 0,
                'record_from' => 'Партнёры: Mobile app new widget',
            ],
        ]);

        $record = new Record([
            'company_id' => 1114763,
            'client_id' => 263913558,
            'record_id' => 1723005936,
            'created_user_id' => null,
            'record_from' => null,
            'staff_name' => 'Дорошенко Анна Андреевна',
        ]);

        $fields = Setting::YCGetFields($yc, $record);

        $this->assertSame('Внешний источник', $fields['created_user_role_name']);
        $this->assertSame('Не сотрудник', $fields['created_user_department']);
        $this->assertSame('Партнёры: Mobile app new widget', $fields['record_from']);
    }

    public function test_yc_get_fields_uses_fallback_when_record_from_is_empty(): void
    {
        $yc = $this->getMockBuilder(YClientsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getClient',
                'getRecord',
                'getBranchTitle',
                'getUserPermissions',
                'getUserRoles',
                'getStaff',
                'findStaffByUserId',
                'findPositionTitle',
            ])
            ->getMock();

        $yc->method('getClient')->willReturn(
            (object)[
                'data' => (object)[
                    'sex' => null,
                    'birth_date' => null,
                    'visits' => 1,
                    'paid' => 0,
                ],
            ]
        );
        $yc->method('getRecord')->willReturn(
            (object)[
                'data' => (object)[
                    'created_user_id' => 13172360,
                    'record_from' => '',
                ],
            ]
        );
        $yc->method('getBranchTitle')->willReturn('Филиал');
        $yc->method('getUserPermissions')->willReturn((object)['success' => false, 'data' => null]);
        $yc->method('getUserRoles')->willReturn((object)['success' => false, 'data' => null]);
        $yc->method('getStaff')->willReturn(null);
        $yc->method('findStaffByUserId')->willReturn(
            (object)[
                'specialization' => 'Администратор',
                'position' => null,
            ]
        );
        $yc->method('findPositionTitle')->willReturn(null);

        $record = new Record([
            'company_id' => 331981,
            'client_id' => 373533042,
            'record_id' => 1724004600,
            'created_user_id' => 13172360,
            'record_from' => '',
        ]);

        $fields = Setting::YCGetFields($yc, $record);

        $this->assertSame('Сотрудник', $fields['created_user_role_name']);
        $this->assertSame('Администратор', $fields['created_user_department']);
        $this->assertSame('Не указан', $fields['record_from']);
    }

    public function test_yc_get_fields_uses_creator_role_as_department_when_creator_staff_is_missing(): void
    {
        $yc = $this->getMockBuilder(YClientsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getClient',
                'getRecord',
                'getBranchTitle',
                'getUserPermissions',
                'getUserRoles',
                'getStaff',
                'findStaffByUserId',
                'findPositionTitle',
            ])
            ->getMock();

        $yc->method('getClient')->willReturn(
            (object)[
                'data' => (object)[
                    'sex' => null,
                    'birth_date' => null,
                    'visits' => 1,
                    'paid' => 0,
                ],
            ]
        );
        $yc->method('getRecord')->willReturn(
            (object)[
                'data' => (object)[
                    'created_user_id' => 12241065,
                    'record_from' => '',
                    'staff' => (object)[
                        'specialization' => 'Врач-косметолог (инъекции)',
                        'position' => (object)[
                            'title' => 'Врач-косметолог (инъекции)',
                        ],
                    ],
                ],
            ]
        );
        $yc->method('getBranchTitle')->willReturn('Филиал');
        $yc->method('getUserPermissions')->willReturn(
            (object)[
                'success' => true,
                'data' => (object)[
                    'user_role' => 'call_center',
                    'staff_id' => null,
                    'user_permissions' => [
                        (object)[
                            'slug' => 'timetable_position_id',
                            'value' => 0,
                        ],
                    ],
                ],
            ]
        );
        $yc->method('getUserRoles')->willReturn((object)['success' => true, 'data' => []]);
        $yc->method('getStaff')->willReturn(null);
        $yc->method('findStaffByUserId')->willReturn(null);
        $yc->method('findPositionTitle')->willReturn(null);

        $record = new Record([
            'company_id' => 1114763,
            'client_id' => 331751379,
            'record_id' => 1724269599,
            'created_user_id' => 12241065,
            'record_from' => '',
        ]);

        $fields = Setting::YCGetFields($yc, $record);

        $this->assertSame('Кол-центр', $fields['created_user_role_name']);
        $this->assertSame('Кол-центр', $fields['created_user_department']);
        $this->assertSame('Не указан', $fields['record_from']);
    }
}
