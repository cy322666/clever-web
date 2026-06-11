<?php

namespace App\Models\Integrations\YClients;

use App\Filament\Resources\Integrations\YClients\YClientsResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\amoCRM\Field;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
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
            'call_center' => 'Кол-центр',
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

    private function amoField(int|string|null $fieldId, string $entityType): ?Field
    {
        if (empty($fieldId)) {
            return null;
        }

        return Field::query()
            ->where('field_id', $fieldId)
            ->where('entity_type', $entityType)
            ->where('user_id', $this->user_id)
            ->first();
    }

    private static function debugLog(string $message, array $context = []): void
    {
        if (function_exists('app') && app()->bound('log')) {
            logger()->info($message, $context);
        }
    }

    private static function setAmoFieldById(Contact|Lead $entity, Field $field, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $customField = $entity->customFields->byId((int)$field->field_id);

        if (!$customField) {
            throw new RuntimeException(
                "amoCRM custom field not found on entity: {$field->name} ({$field->field_id})"
            );
        }

        $customField->setValue($value);
    }

    private static function amoFieldHasValue(Contact|Lead $entity, Field $field): bool
    {
        $customField = $entity->customFields->byId((int)$field->field_id);

        if (!$customField) {
            return false;
        }

        return self::filledAmoValue($customField->getValue());
    }

    private static function filledAmoValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return count(array_filter($value, fn($item) => self::filledAmoValue($item))) > 0;
        }

        return true;
    }

    private function leadAlreadyHasRecordIdentity(Lead $lead, array $mapping): bool
    {
        $hasServices = false;
        $hasDateOrTime = false;

        foreach ($mapping as $field) {
            $fieldYc = $field['field_yc'] ?? null;

            if (!in_array($fieldYc, ['services', 'record_datetime', 'record_date', 'record_time'], true)) {
                continue;
            }

            $amoField = $this->amoField($field['field_amo'] ?? null, 'leads');

            if (!$amoField || !self::amoFieldHasValue($lead, $amoField)) {
                continue;
            }

            if ($fieldYc === 'services') {
                $hasServices = true;
                continue;
            }

            $hasDateOrTime = true;
        }

        return $hasServices && $hasDateOrTime;
    }

    private static function mappingRows(mixed $mapping): array
    {
        if (blank($mapping)) {
            return [];
        }

        $rows = is_array($mapping)
            ? $mapping
            : json_decode((string)$mapping, true);

        if (!is_array($rows)) {
            return [];
        }

        return array_values(
            array_filter(
                $rows,
                fn($row): bool => is_array($row)
                    && (!blank($row['field_yc'] ?? null) || !blank($row['field_amo'] ?? null))
            )
        );
    }

    private static function recordDateTime(?string $datetime): ?\Carbon\Carbon
    {
        if (empty($datetime)) {
            return null;
        }

        try {
            return \Carbon\Carbon::createFromFormat('Y.m.d H:i:s', $datetime);
        } catch (\Throwable) {
            return \Carbon\Carbon::parse($datetime);
        }
    }

    private static function formattedDateTime(?string $datetime): ?string
    {
        if (empty($datetime)) {
            return null;
        }

        try {
            return self::recordDateTime($datetime)?->format('d.m.Y H:i');
        } catch (\Throwable) {
            return (string)$datetime;
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
            'record_datetime' => self::humanFieldLabel('Дата и время записи'),
            'record_date' => self::humanFieldLabel('Дата записи'),
            'record_time' => self::humanFieldLabel('Время записи'),
            'record_from' => self::humanFieldLabel('Источник записи'),
            'create_date' => self::humanFieldLabel('Дата создания'),
            'created_user_name' => self::humanFieldLabel('Кто создал'),
            'created_user_role_name' => self::humanFieldLabel('Роль создателя'),
            'created_user_department' => self::humanFieldLabel('Отдел создателя'),

            'visits' => self::fieldLabel('Кол-во визитов', 'visits'),
            'services' => self::fieldLabel('Услуги', 'services'),
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
            'record_datetime',
            'record_date',
            'record_time',
            'record_from',
            'create_date',
            'created_user_name',
            'created_user_role_name',
            'created_user_department',

            'visits',
            'services',
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

        $clientYC = data_get($client->getClient($record->company_id, $record->client_id), 'data');
        $recordYC = $client->getRecord($record->company_id, $record->record_id)?->data ?? null;
        $createdUserId = $record->created_user_id;
        $recordFrom = $record->record_from ?: data_get($recordYC, 'record_from');
        $createDate = $record->create_date ?: data_get($recordYC, 'create_date');

        if ($createdUserId === null || $createdUserId === '') {
            $createdUserId = data_get($recordYC, 'created_user_id');
        }

        if (($record->record_from !== $recordFrom
                || (string)$record->created_user_id !== (string)$createdUserId
                || (string)$record->create_date !== (string)$createDate)
            && $record->exists) {
            $record->forceFill([
                'created_user_id' => $createdUserId,
                'record_from' => $recordFrom,
                'create_date' => $createDate,
            ])->save();
        }

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

        $fields['branch'] = $client->getBranchTitle($record->company_id) ?: (string)$record->company_id;
        $fields['company_id'] = $record->company_id;
        $fields['record_id'] = $record->record_id;
        $recordDateTime = self::recordDateTime($record->datetime);
        $fields['record_datetime'] = $recordDateTime?->format('d.m.Y H:i');
        $fields['record_date'] = $recordDateTime?->format('d.m.Y');
        $fields['record_time'] = $recordDateTime?->format('H:i');
        $fields['record_from'] = $recordFrom ?: 'Не указан';
        $fields['create_date'] = self::formattedDateTime($createDate);
        $fields['created_user_name'] = null;
        $fields['created_user_role_name'] = null;
        $fields['created_user_department'] = null;

        if (empty($createdUserId)) {
            $fields['created_user_name'] = 'Не сотрудник';
            $fields['created_user_role_name'] = 'Внешний источник';
            $fields['created_user_department'] = 'Не сотрудник';
        } else {
            $createdUser = $client->getUserPermissions($record->company_id, $createdUserId);
            $createdUserRoles = $client->getUserRoles($record->company_id, $createdUserId);

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
                $staff = $client->findStaffByUserId($record->company_id, $createdUserId);
            }

            $fields['created_user_name'] = data_get($staff, 'data.name')
                ?: data_get($staff, 'data.0.name')
                    ?: data_get($staff, 'name')
                        ?: (string)$createdUserId;

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
                'created_user_id' => $createdUserId,
                'record_from' => $recordFrom,
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
                'resolved_name' => $fields['created_user_name'],
                'resolved_role' => $fields['created_user_role_name'],
                'resolved_department' => $fields['created_user_department'],
                'permissions_success' => data_get($createdUser, 'success'),
                'roles_success' => data_get($createdUserRoles, 'success'),
            ]);
        }

        // $fields['branches'] = $client->query()->state()->getData();

//        $fields['categories'] = $categories;

        $fields['sex'] = match (data_get($clientYC, 'sex')) {
            'Женский' => 'Ж',
            'Мужской' => 'М',
            default => null,
        };

        $fields['birth_date'] = data_get($clientYC, 'birth_date') ?? data_get($clientYC, 'birthday');
        $fields['discount'] = data_get($clientYC, 'discount');
        $fields['comment'] = data_get($clientYC, 'comment');
        $fields['sms_check'] = data_get($clientYC, 'sms_check') !== null
            ? ((int)data_get($clientYC, 'sms_check') === 1 ? 'Да' : 'Нет')
            : null;
        $fields['sms_not'] = data_get($clientYC, 'sms_not') !== null
            ? ((int)data_get($clientYC, 'sms_not') === 1 ? 'Нет' : 'Да')
            : null;

        $fields['visits'] = data_get($clientYC, 'visits');
        $fields['services'] = trim((string)$record->title);
        $fields['staff'] = $record->staff_name;
        $fields['paid'] = data_get($clientYC, 'paid');
        $fields['ltv'] = data_get($clientYC, 'paid');
        $fields['client_id'] = $record->client_id;

        return $fields;
    }

    public function YCSetContactFields(Contact $contact, array $ycFields): Contact
    {
        $body = self::mappingRows($this->fields_contact);

        if (!$body) {
            return $contact;
        }

        // field_amo stores amoCRM field_id, not the local amocrm_fields primary key.
        foreach ($body as $field) {
            $amoField = $this->amoField($field['field_amo'] ?? null, 'contacts');
            $fieldYc = $field['field_yc'] ?? null;
            $value = $fieldYc ? ($ycFields[$fieldYc] ?? null) : null;

            self::debugLog('YClients contact field mapping.', [
                'setting_id' => $this->id,
                'field_yc' => $fieldYc,
                'field_amo' => $field['field_amo'] ?? null,
                'field_name' => $amoField?->name,
                'value' => $value,
            ]);

            if (!$amoField) {
                throw new RuntimeException(
                    'amoCRM contact field mapping not found: ' . ($field['field_amo'] ?? 'null')
                );
            }

            self::setAmoFieldById($contact, $amoField, $value);
        }
        $contact->save();

        return $contact;
    }

    public function YCSetLeadFields(Lead $lead, array $ycFields): Lead
    {
        $body = self::mappingRows($this->fields_lead);

        if (!$body) {
            return $lead;
        }

        if ($this->leadAlreadyHasRecordIdentity($lead, $body)) {
            self::debugLog('YClients lead field mapping skipped: lead already has services and date/time.', [
                'setting_id' => $this->id,
                'lead_id' => $lead->id,
            ]);

            return $lead;
        }

        foreach ($body as $field) {
            $amoField = $this->amoField($field['field_amo'] ?? null, 'leads');
            $fieldYc = $field['field_yc'] ?? null;
            $value = $fieldYc ? ($ycFields[$fieldYc] ?? null) : null;

            self::debugLog('YClients lead field mapping.', [
                'setting_id' => $this->id,
                'field_yc' => $fieldYc,
                'field_amo' => $field['field_amo'] ?? null,
                'field_name' => $amoField?->name,
                'value' => $value,
            ]);

            if (!$amoField) {
                throw new RuntimeException('amoCRM lead field mapping not found: ' . ($field['field_amo'] ?? 'null'));
            }

            self::setAmoFieldById($lead, $amoField, $value);
        }
        $lead->save();

        return $lead;
    }
}
