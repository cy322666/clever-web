<?php

namespace Tests\Unit\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\YClients as YClientsService;
use PHPUnit\Framework\TestCase;

class SettingFieldsTest extends TestCase
{
    public function test_yc_fields_select_shows_system_keys_in_labels(): void
    {
        $fields = Setting::YCfieldsSelect();

        $this->assertSame('Запись', $fields['record_id']);
        $this->assertSame('Роль создателя', $fields['created_user_role_name']);
        $this->assertSame('Отдел создателя', $fields['created_user_department']);
        $this->assertSame('Филиал записи', $fields['company_id']);
        $this->assertSame('Пол (sex) - список М/Ж/строка', $fields['sex']);
        $this->assertSame('Сумма покупок (paid)', $fields['paid']);
    }

    public function test_yc_get_fields_includes_record_and_company_ids(): void
    {
        $yc = $this->getMockBuilder(YClientsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getClient', 'getBranchTitle', 'getUserPermissions', 'getUserRoles', 'getStaff'])
            ->getMock();

        $yc->method('getClient')->willReturn(
            (object)[
                'data' => (object)[
                    'sex' => 'Женский',
                    'birth_date' => '1990-01-01',
                    'visits' => 12,
                    'paid' => 3456,
                    'created_user_id' => 4321,
                ],
            ]
        );

        $yc->method('getBranchTitle')->willReturn('Филиал 10');
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
            'created_user_id' => 4321,
            'staff_name' => 'Мастер',
        ]);

        $fields = Setting::YCGetFields($yc, $record);

        $this->assertSame(10, $fields['company_id']);
        $this->assertSame(777, $fields['record_id']);
        $this->assertSame('Сотрудник', $fields['created_user_role_name']);
        $this->assertSame('Администратор', $fields['created_user_department']);
        $this->assertSame('Филиал 10', $fields['branch']);
        $this->assertSame('Ж', $fields['sex']);
        $this->assertSame('1990-01-01', $fields['birth_date']);
        $this->assertSame(12, $fields['visits']);
        $this->assertSame('Мастер', $fields['staff']);
        $this->assertSame(3456, $fields['paid']);
        $this->assertSame(3456, $fields['ltv']);
        $this->assertSame(555, $fields['client_id']);
    }

    public function test_yc_get_fields_resolves_created_user_role_and_department_from_permissions(): void
    {
        $yc = $this->getMockBuilder(YClientsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getClient',
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
}
