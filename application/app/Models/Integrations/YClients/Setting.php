<?php

namespace App\Models\Integrations\YClients;

use App\Filament\Resources\Integrations\YClients\YClientsResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\amoCRM\Field;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Contacts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'yclients_settings';

    public static string $resource = YClientsResource::class;

    static array $cost = [
        '1_month' => '2 990 ₽',
        '6_month' => '14 900 ₽',
        '12_month' => '24 900 ₽',
    ];

    protected $fillable = [
        'active',
        'status_id_cancel',
        'status_id_wait',
        'status_id_came',
        'status_id_confirm',
        'status_id_delete',
        'pipelines',
        'user_token',
        'partner_token',
        'login',
        'password',
        'branches',
        'user_id',
        'account_id',
        'fields_contact',
        'fields_lead',
    ];

    protected $casts = [
        'pipelines' => 'array',
    ];

    private static function fieldLabel(string $title, string $key, ?string $description = null): string
    {
        $label = $title . ' (' . $key . ')';

        if ($description) {
            $label .= ' - ' . $description;
        }

        return $label;
    }

    private static function humanFieldLabel(string $title, ?string $description = null): string
    {
        return $description ? $title . ' - ' . $description : $title;
    }

    private static function createdUserRoleTitle(?string $role): ?string
    {
        return match ($role) {
            'owner' => 'Владелец',
            'worker' => 'Сотрудник',
            'administrator' => 'Администратор',
            'accountant' => 'Бухгалтер',
            'manager' => 'Менеджер',
            'client' => 'Клиент',
            default => $role ? trim((string)$role) : null,
        };
    }

    private static function permissionValue(?object $permissions, string $slug): mixed
    {
        $permission = collect(data_get($permissions, 'data.user_permissions', []))
            ->first(function ($item) use ($slug) {
                return data_get($item, 'slug') === $slug;
            });

        return data_get($permission, 'value');
    }

    private static function amoFieldName(int|string|null $fieldId, string $entityType): ?string
    {
        if (empty($fieldId)) {
            return null;
        }

        return Field::query()
            ->where('field_id', $fieldId)
            ->where('entity_type', $entityType)
            ->first()
            ?->name;
    }

    private static function debugLog(string $message, array $context = []): void
    {
        if (function_exists('app') && app()->bound('log')) {
            logger()->info($message, $context);
        }
    }

    public static function YCfieldsSelect(): array
    {
        return [
            'sex' => self::fieldLabel('Пол', 'sex', 'список М/Ж/строка'),
            'birth_date' => self::fieldLabel('Дата рождения', 'birth_date', 'дата'),
            'discount' => self::fieldLabel('Скидка', 'discount', 'число'),
            'comment' => self::fieldLabel('Комментарий', 'comment', 'строка'),
            'sms_check' => self::fieldLabel('Поздравлять с ДР', 'sms_check', 'флаг/строка'),
            'sms_not' => self::fieldLabel('Отправлять рассылку', 'sms_not', 'флаг/строка'),
//            'categories' => 'Категории клиента (строка)',
            'branch' => self::humanFieldLabel('Филиал'),
            'company_id' => self::humanFieldLabel('Филиал записи'),
            'record_id' => self::humanFieldLabel('Запись'),
            'created_user_role_name' => self::humanFieldLabel('Роль создателя'),
            'created_user_department' => self::humanFieldLabel('Отдел создателя'),

            'visits' => self::fieldLabel('Кол-во визитов', 'visits'),
            'staff' => self::fieldLabel('Мастер', 'staff'),
            'paid' => self::fieldLabel('Сумма покупок', 'paid'),
            'ltv' => self::fieldLabel('Выручка', 'ltv'),
            'client_id' => self::humanFieldLabel('Клиент'),
        ];
    }

    public static function YCfields(): array
    {
        return [
            'sex',
            'birth_date',
            'discount',
            'comment',
            'sms_check',
            'sms_not',
//            'categories',
            'branch',
            'company_id',
            'record_id',
            'created_user_role_name',
            'created_user_department',

            'visits',
            'staff',
            'paid',
            'ltv',
            'client_id',
        ];
    }

    /**
     * @throws ConnectionException
     */
    public static function YCGetFields(\App\Services\YClients\YClients $client, Record $record): array
    {
        $fields = static::YCfields();

        $clientYC = $client->getClient($record->company_id, $record->client_id)->data;

//        $categories = '';
//
//        if (count($clientYC->object()->getCategories()) > 0) {
//            if (is_array($clientYC->object()->getCategories()) && count($clientYC->object()->getCategories())) {
//                foreach ($clientYC->object()->getCategories() as $category) {
//
//                    $categories .= $category['title'] ?? null . ', ';
//                }
//                $categories = str_replace(',', '', $categories);
//            }
//        }

        $fields['branch'] = $client->getBranchTitle($record->company_id);
        $fields['company_id'] = $record->company_id;
        $fields['record_id'] = $record->record_id;
        $fields['created_user_role_name'] = null;
        $fields['created_user_department'] = null;

        if (!empty($record->created_user_id)) {
            $createdUser = $client->getUserPermissions($record->company_id, $record->created_user_id);
            $createdUserRoles = $client->getUserRoles($record->company_id, $record->created_user_id);

            $role = data_get($createdUser, 'data.user_role');
            $roleTitle = self::createdUserRoleTitle($role);

            if (!$roleTitle) {
                $roleTitle = self::createdUserRoleTitle(data_get($createdUserRoles, 'data.0.title'))
                    ?: self::createdUserRoleTitle(data_get($createdUserRoles, 'data.0.slug'));
            }

            $fields['created_user_role_name'] = $roleTitle ?: 'Сотрудник';

            $staffId = data_get($createdUser, 'data.staff_id');
            $positionId = self::permissionValue($createdUser, 'timetable_position_id');
            $staff = null;

            if (!empty($staffId)) {
                $staff = $client->getStaff($record->company_id, $staffId);
            }

            if (!$staff) {
                $staff = $client->findStaffByUserId($record->company_id, $record->created_user_id);
            }

            $fields['created_user_department'] = data_get($staff, 'data.position.title')
                ?: data_get($staff, 'data.0.position.title')
                ?: data_get($staff, 'position.title')
                        ?: data_get($staff, 'data.specialization')
                            ?: data_get($staff, 'data.0.specialization')
                                ?: data_get($staff, 'specialization')
                                    ?: $client->findPositionTitle($record->company_id, $positionId)
                                        ?: $roleTitle
                                            ?: 'Сотрудник';

            self::debugLog('YClients created user fields resolved.', [
                'record_db_id' => $record->id,
                'record_id' => $record->record_id,
                'company_id' => $record->company_id,
                'created_user_id' => $record->created_user_id,
                'raw_user_role' => $role,
                'role_title' => $roleTitle,
                'staff_id' => $staffId,
                'position_id' => $positionId,
                'staff_position_title' => data_get($staff, 'data.position.title')
                    ?: data_get($staff, 'data.0.position.title')
                        ?: data_get($staff, 'position.title'),
                'staff_specialization' => data_get($staff, 'data.specialization')
                    ?: data_get($staff, 'data.0.specialization')
                        ?: data_get($staff, 'specialization'),
                'resolved_role' => $fields['created_user_role_name'],
                'resolved_department' => $fields['created_user_department'],
                'permissions_success' => data_get($createdUser, 'success'),
                'roles_success' => data_get($createdUserRoles, 'success'),
            ]);
        }

        // $fields['branches'] = $client->query()->state()->getData();

//        $fields['categories'] = $categories;

        $fields['sex'] = match ($clientYC->sex) {
            'Женский' => 'Ж',
            'Мужской' => 'М',
            default => null,
        };

        $fields['birth_date'] = $clientYC->birth_date;

        $fields['visits'] = $clientYC->visits;
        $fields['staff'] = $record->staff_name;
        $fields['paid'] = $clientYC->paid;
        $fields['ltv'] = $clientYC->paid;
        $fields['client_id'] = $record->client_id;

        return $fields;
    }

    public function YCSetContactFields(Contact $contact, array $ycFields): Contact
    {
        $body = json_decode($this->fields_contact, true);

        // field_amo stores amoCRM field_id, not the local amocrm_fields primary key.
        foreach ($body as $field) {
            $fieldName = self::amoFieldName($field['field_amo'] ?? null, 'contacts');
            $fieldYc = $field['field_yc'] ?? null;
            $value = $fieldYc ? ($ycFields[$fieldYc] ?? null) : null;

            self::debugLog('YClients contact field mapping.', [
                'setting_id' => $this->id,
                'field_yc' => $fieldYc,
                'field_amo' => $field['field_amo'] ?? null,
                'field_name' => $fieldName,
                'value' => $value,
            ]);

            if ($fieldName)
                $contact = Contacts::setField($contact, $fieldName, $value);
        }
        $contact->save();

        return $contact;
    }

    public function YCSetLeadFields(Lead $lead, array $ycFields): Lead
    {
        $body = json_decode($this->fields_lead, true);

        if (!$body) {
            return $lead;
        }

        foreach ($body as $field) {
            $fieldName = self::amoFieldName($field['field_amo'] ?? null, 'leads');
            $fieldYc = $field['field_yc'] ?? null;
            $value = $fieldYc ? ($ycFields[$fieldYc] ?? null) : null;

            self::debugLog('YClients lead field mapping.', [
                'setting_id' => $this->id,
                'field_yc' => $fieldYc,
                'field_amo' => $field['field_amo'] ?? null,
                'field_name' => $fieldName,
                'value' => $value,
            ]);

            if ($fieldName) {
                $lead = Leads::setField($lead, $fieldName, $value);
            }
        }
        $lead->save();

        return $lead;
    }
}
