<?php

namespace App\Models\Integrations\YClients;

use App\Filament\Resources\Integrations\YClients\YClientsResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Services\amoCRM\Client as AmoClient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;
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
        'default_responsible_user_id',
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

    private static function optionalYClientsRequest(callable $request, string $requestName, Record $record): mixed
    {
        try {
            return $request();
        } catch (Throwable $e) {
            self::debugLog('Optional YClients request skipped.', [
                'request' => $requestName,
                'record_db_id' => $record->id,
                'record_id' => $record->record_id,
                'company_id' => $record->company_id,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    private static function setAmoFieldById(
        Contact|Lead $entity,
        Field $field,
        mixed $value,
        ?AmoClient $amoApi = null,
        ?string $entityType = null
    ): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (is_array($value) && $value === []) {
            return;
        }

        $customField = $entity->customFields->byId((int)$field->field_id);

        if (!$customField) {
            throw new RuntimeException(
                "amoCRM custom field not found on entity: {$field->name} ({$field->field_id})"
            );
        }

        $value = self::normalizeAmoEnumValue($customField, $field, $value);

        try {
            self::setCustomFieldValue($customField, $value);
        } catch (Throwable $e) {
            if (!$amoApi || !$entityType || !self::isEnumNotFound($e) || !self::isEnumField($field)) {
                throw $e;
            }

            $missingValues = self::missingEnumValues($customField, $field, $value);

            if (!$missingValues) {
                throw $e;
            }

            self::appendAmoEnumValues($amoApi, $field, $entityType, $missingValues);
            self::refreshRuntimeEnums($customField, $field);

            $value = self::normalizeAmoEnumValue($customField, $field, $value);
            self::setCustomFieldValue($customField, $value);
        }
    }

    private static function setCustomFieldValue(object $customField, mixed $value): void
    {
        if (is_array($value) && method_exists($customField, 'setValues')) {
            if (method_exists($customField, 'reset')) {
                $customField->reset();
            }

            $customField->setValues($value);

            return;
        }

        $customField->setValue($value);
    }

    private static function isEnumNotFound(Throwable $e): bool
    {
        return stripos($e->getMessage(), 'enum not found') !== false;
    }

    private static function isEnumField(Field $field): bool
    {
        return in_array((string)$field->type, ['select', 'multiselect', 'radiobutton'], true);
    }

    private static function isMultiEnumField(Field $field): bool
    {
        return (string)$field->type === 'multiselect';
    }

    private static function amoEntityCustomFieldsEndpoint(string $entityType, int $fieldId): string
    {
        return sprintf('/api/v4/%s/custom_fields/%d', $entityType, $fieldId);
    }

    private static function missingEnumValues(object $customField, Field $field, mixed $value): array
    {
        $enumValues = self::amoEnumValues($customField, $field);
        $values = is_array($value) ? $value : [$value];

        return collect($values)
            ->filter(fn(mixed $item): bool => is_scalar($item) && trim((string)$item) !== '')
            ->map(fn(mixed $item): string => trim((string)$item))
            ->reject(fn(string $item): bool => self::enumValueExists($enumValues, $item))
            ->unique(fn(string $item): string => self::normalizeEnumText($item))
            ->values()
            ->all();
    }

    private static function enumValueExists(array $enumValues, string $value): bool
    {
        $normalizedValue = self::normalizeEnumText($value);

        foreach ($enumValues as $enumValue) {
            if (self::normalizeEnumText((string)$enumValue) === $normalizedValue) {
                return true;
            }
        }

        return false;
    }

    private static function appendAmoEnumValues(
        AmoClient $amoApi,
        Field $field,
        string $entityType,
        array $values
    ): void {
        $endpoint = self::amoEntityCustomFieldsEndpoint($entityType, (int)$field->field_id);
        $remoteField = $amoApi->service->ajax()->get($endpoint);
        $enums = self::amoEnumPayload($remoteField->enums ?? null);
        $knownValues = self::extractEnumValues($remoteField->enums ?? null);
        $sort = collect($enums)->max('sort') ?: 0;

        foreach ($values as $value) {
            if (self::enumValueExists($knownValues, $value)) {
                continue;
            }

            $sort += 10;
            $enums[] = [
                'value' => $value,
                'sort' => $sort,
            ];
            $knownValues[] = $value;
        }

        if (!$enums) {
            return;
        }

        $updatedField = $amoApi->service->ajax()->patch($endpoint, [
            'enums' => $enums,
        ]);

        $updatedEnums = $updatedField->enums ?? null;

        if (!$updatedEnums) {
            $updatedEnums = $amoApi->service->ajax()->get($endpoint)->enums ?? null;
        }

        $field->enums = json_encode($updatedEnums, JSON_UNESCAPED_UNICODE);
        $field->save();
    }

    private static function amoEnumPayload(mixed $enums): array
    {
        if ($enums instanceof \stdClass) {
            $enums = get_object_vars($enums);
        }

        if (!is_array($enums)) {
            return [];
        }

        $payload = [];
        $sort = 0;

        foreach ($enums as $key => $enum) {
            $value = is_array($enum) || $enum instanceof \stdClass
                ? data_get($enum, 'value')
                : $enum;

            if ($value === null || $value === '') {
                continue;
            }

            $row = ['value' => (string)$value];
            $id = is_array($enum) || $enum instanceof \stdClass
                ? data_get($enum, 'id')
                : (is_numeric($key) ? (int)$key : null);

            if ($id) {
                $row['id'] = (int)$id;
            }

            $enumSort = is_array($enum) || $enum instanceof \stdClass
                ? data_get($enum, 'sort')
                : null;

            $sort = (int)($enumSort ?: $sort + 10);
            $row['sort'] = $sort;

            $payload[] = $row;
        }

        return $payload;
    }

    private static function refreshRuntimeEnums(object $customField, Field $field): void
    {
        try {
            $enumValues = self::extractEnumValues(
                is_string($field->enums) ? json_decode($field->enums, true) : $field->enums
            );

            if ($enumValues) {
                $customField->field->enums = (object)$enumValues;
            }
        } catch (Throwable) {
            //
        }
    }

    private static function normalizeAmoEnumValue(object $customField, Field $field, mixed $value): mixed
    {
        $enumValues = self::amoEnumValues($customField, $field);

        if (!$enumValues) {
            return $value;
        }

        if (is_array($value)) {
            return array_map(
                fn(mixed $item): mixed => self::canonicalAmoEnumValue($enumValues, $item),
                $value
            );
        }

        return self::canonicalAmoEnumValue($enumValues, $value);
    }

    private static function canonicalAmoEnumValue(array $enumValues, mixed $value): mixed
    {
        if (!is_scalar($value)) {
            return $value;
        }

        $value = (string)$value;

        foreach ($enumValues as $enumValue) {
            if ((string)$enumValue === $value) {
                return $enumValue;
            }
        }

        $normalizedValue = self::normalizeEnumText($value);

        foreach ($enumValues as $enumValue) {
            if (self::normalizeEnumText((string)$enumValue) === $normalizedValue) {
                return $enumValue;
            }
        }

        return $value;
    }

    private static function amoEnumValues(object $customField, Field $field): array
    {
        try {
            $runtimeEnums = $customField->field->enums ?? null;
        } catch (Throwable) {
            $runtimeEnums = null;
        }

        $values = self::extractEnumValues($runtimeEnums);

        if ($values) {
            return $values;
        }

        $storedEnums = is_string($field->enums)
            ? json_decode($field->enums, true)
            : $field->enums;

        return self::extractEnumValues($storedEnums);
    }

    private static function extractEnumValues(mixed $enums): array
    {
        if ($enums instanceof \stdClass) {
            $enums = get_object_vars($enums);
        }

        if (!is_array($enums)) {
            return [];
        }

        $values = [];

        foreach ($enums as $key => $enum) {
            if (is_array($enum) || $enum instanceof \stdClass) {
                $enumValue = data_get($enum, 'value');
                $enumKey = data_get($enum, 'id', $key);
            } else {
                $enumValue = $enum;
                $enumKey = $key;
            }

            if ($enumValue === null || $enumValue === '') {
                continue;
            }

            $values[(string)$enumKey] = (string)$enumValue;
        }

        return $values;
    }

    private static function normalizeEnumText(string $value): string
    {
        $value = trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $value));

        return function_exists('mb_strtoupper')
            ? mb_strtoupper($value)
            : strtoupper($value);
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

    public function hasContactFieldMapping(string $fieldYc): bool
    {
        foreach (self::mappingRows($this->fields_contact) as $field) {
            if (($field['field_yc'] ?? null) === $fieldYc && !blank($field['field_amo'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    public function responsibleUserIdForRecord(Record $record): ?int
    {
        $ycUserKey = (string)$record->company_id . ':' . (string)$record->created_user_id;
        $amoUserId = (int)ResponsibleMapping::query()
            ->where('setting_id', $this->id)
            ->where('active', true)
            ->get()
            ->first(fn(ResponsibleMapping $mapping): bool => in_array($ycUserKey, $mapping->yc_user_keys ?? [], true))
            ?->amo_user_id;

        $amoUserId = $amoUserId > 0 ? $amoUserId : (int)$this->default_responsible_user_id;

        return Staff::query()
            ->where('user_id', $this->user_id)
            ->where('staff_id', $amoUserId)
            ->where('active', true)
            ->exists()
            ? $amoUserId
            : null;
    }

    public function responsibleMappings(): HasMany
    {
        return $this->hasMany(ResponsibleMapping::class, 'setting_id');
    }

    public function yclientsUsers(): HasMany
    {
        return $this->hasMany(YClientsUser::class, 'setting_id');
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
            'categories' => self::fieldLabel('Категория', 'categories', 'строка'),
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
            'categories',
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

        $clientYC = null;

        if ((int)$record->client_id > 0) {
            $clientYC = data_get($client->getClient($record->company_id, $record->client_id), 'data');
        }
        $recordYC = self::optionalYClientsRequest(
            fn() => $client->getRecord($record->company_id, $record->record_id),
            'record',
            $record
        )?->data ?? null;
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

        $fields['branch'] = self::optionalYClientsRequest(
            fn() => $client->getBranchTitle($record->company_id),
            'branch-title',
            $record
        ) ?: (string)$record->company_id;
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
            $createdUser = self::optionalYClientsRequest(
                fn() => $client->getUserPermissions($record->company_id, $createdUserId),
                'created-user-permissions',
                $record
            );
            $createdUserRoles = self::optionalYClientsRequest(
                fn() => $client->getUserRoles($record->company_id, $createdUserId),
                'created-user-roles',
                $record
            );

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
            $companyUser = null;

            if (!empty($staffId)) {
                $staff = self::optionalYClientsRequest(
                    fn() => $client->getStaff($record->company_id, $staffId),
                    'created-user-staff',
                    $record
                );
            }

            if (!$staff) {
                $staff = self::optionalYClientsRequest(
                    fn() => $client->findStaffByUserId($record->company_id, $createdUserId),
                    'created-user-staff-list',
                    $record
                );
            }

            if (!$staff) {
                $companyUser = self::optionalYClientsRequest(
                    fn() => $client->findCompanyUserById($record->company_id, $createdUserId),
                    'created-user-company-user',
                    $record
                );
            }

            $fields['created_user_name'] = data_get($staff, 'data.name')
                ?: data_get($staff, 'data.0.name')
                    ?: data_get($staff, 'name')
                        ?: data_get($companyUser, 'name')
                            ?: data_get($createdUser, 'data.name')
                                ?: data_get($createdUser, 'data.login')
                                    ?: $roleTitle
                                        ?: 'Пользователь YClients';

            $fields['created_user_department'] = data_get($staff, 'data.position.title')
                ?: data_get($staff, 'data.0.position.title')
                ?: data_get($staff, 'position.title')
                        ?: data_get($staff, 'data.specialization')
                            ?: data_get($staff, 'data.0.specialization')
                                ?: data_get($staff, 'specialization')
                                    ?: self::optionalYClientsRequest(
                                        fn() => $client->findPositionTitle($record->company_id, $positionId),
                                        'created-user-position',
                                        $record
                                    )
                                        ?: $roleTitle
                                            ?: 'Сотрудник';

//            self::debugLog('YClients created user fields resolved.', [
//                'record_db_id' => $record->id,
//                'record_id' => $record->record_id,
//                'company_id' => $record->company_id,
//                'created_user_id' => $createdUserId,
//                'record_from' => $recordFrom,
//                'raw_user_role' => $role,
//                'role_title' => $roleTitle,
//                'staff_id' => $staffId,
//                'position_id' => $positionId,
//                'company_user_name' => data_get($companyUser, 'name'),
//                'staff_position_title' => data_get($staff, 'data.position.title')
//                    ?: data_get($staff, 'data.0.position.title')
//                        ?: data_get($staff, 'position.title'),
//                'staff_specialization' => data_get($staff, 'data.specialization')
//                    ?: data_get($staff, 'data.0.specialization')
//                        ?: data_get($staff, 'specialization'),
//                'resolved_name' => $fields['created_user_name'],
//                'resolved_role' => $fields['created_user_role_name'],
//                'resolved_department' => $fields['created_user_department'],
//                'permissions_success' => data_get($createdUser, 'success'),
//                'roles_success' => data_get($createdUserRoles, 'success'),
//            ]);
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
        $categoryFields = self::YCGetClientCategoryFields($client, $record, $clientYC, $recordYC);
        $fields['categories'] = $categoryFields['categories'];
        $fields['categories_values'] = $categoryFields['categories_values'];

        $fields['visits'] = data_get($clientYC, 'visits');
        $fields['services'] = trim((string)$record->title);
        $fields['staff'] = $record->staff_name;
        $fields['paid'] = data_get($clientYC, 'paid');
        $fields['ltv'] = data_get($clientYC, 'paid');
        $fields['client_id'] = $record->client_id;

        return $fields;
    }

    public static function YCGetClientCategoryFields(
        \App\Services\YClients\YClients $client,
        Record $record,
        mixed $clientYC = null,
        mixed $recordYC = null
    ): array {
        if ($clientYC === null && (int)$record->client_id > 0) {
            $clientYC = data_get($client->getClient($record->company_id, $record->client_id), 'data');
        }

        $values = self::clientCategoryValues($clientYC, $recordYC);

        if ($values || $recordYC !== null) {
            return [
                'categories' => $values ? implode(', ', $values) : null,
                'categories_values' => $values,
            ];
        }

        $recordYC = self::optionalYClientsRequest(
            fn() => $client->getRecord($record->company_id, $record->record_id),
            'record-categories',
            $record
        )?->data ?? null;

        $values = self::clientCategoryValues($clientYC, $recordYC);

        return [
            'categories' => $values ? implode(', ', $values) : null,
            'categories_values' => $values,
        ];
    }

    public static function YCGetClientCategories(
        \App\Services\YClients\YClients $client,
        Record $record,
        mixed $clientYC = null,
        mixed $recordYC = null
    ): ?string {
        return self::YCGetClientCategoryFields($client, $record, $clientYC, $recordYC)['categories'];
    }

    private static function clientCategories(mixed $clientYC, mixed $recordYC = null): ?string
    {
        $values = self::clientCategoryValues($clientYC, $recordYC);

        return $values ? implode(', ', $values) : null;
    }

    private static function clientCategoryValues(mixed $clientYC, mixed $recordYC = null): array
    {
        $categories = data_get($clientYC, 'categories')
            ?? data_get($clientYC, 'category')
            ?? data_get($clientYC, 'client_tags')
            ?? data_get($recordYC, 'client.categories')
            ?? data_get($recordYC, 'client.category')
            ?? data_get($recordYC, 'client.client_tags');

        if ($categories === null || $categories === '') {
            return [];
        }

        if (is_scalar($categories)) {
            $value = trim((string)$categories);

            return $value === '' ? [] : [$value];
        }

        return collect(is_array($categories) ? $categories : [$categories])
            ->map(function (mixed $category): ?string {
                if (is_scalar($category)) {
                    return trim((string)$category);
                }

                $value = data_get($category, 'title')
                    ?? data_get($category, 'name')
                    ?? data_get($category, 'label');

                return is_scalar($value) ? trim((string)$value) : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private static function valueForAmoField(?string $fieldYc, Field $amoField, mixed $value, array $ycFields): mixed
    {
        if ($fieldYc === 'categories' && self::isMultiEnumField($amoField)) {
            $values = $ycFields['categories_values'] ?? null;

            if (is_array($values)) {
                return $values;
            }
        }

        return $value;
    }

    public function YCSetContactFields(
        Contact $contact,
        array $ycFields,
        ?array $onlyYcFields = null,
        ?AmoClient $amoApi = null
    ): Contact
    {
        $body = self::mappingRows($this->fields_contact);

        if (!$body) {
            return $contact;
        }

        // field_amo stores amoCRM field_id, not the local amocrm_fields primary key.
        $applied = false;

        foreach ($body as $field) {
            $amoField = $this->amoField($field['field_amo'] ?? null, 'contacts');
            $fieldYc = $field['field_yc'] ?? null;

            if ($onlyYcFields !== null && !in_array($fieldYc, $onlyYcFields, true)) {
                continue;
            }

            $value = $fieldYc ? ($ycFields[$fieldYc] ?? null) : null;

//            self::debugLog('YClients contact field mapping.', [
//                'setting_id' => $this->id,
//                'field_yc' => $fieldYc,
//                'field_amo' => $field['field_amo'] ?? null,
//                'field_name' => $amoField?->name,
//                'value' => $value,
//            ]);

            if (!$amoField) {
                throw new RuntimeException(
                    'amoCRM contact field mapping not found: ' . ($field['field_amo'] ?? 'null')
                );
            }

            $value = self::valueForAmoField($fieldYc, $amoField, $value, $ycFields);
            self::setAmoFieldById($contact, $amoField, $value, $amoApi, 'contacts');
            $applied = true;
        }

        if ($applied) {
            $contact->save();
        }

        return $contact;
    }

    public function YCSetLeadFields(Lead $lead, array $ycFields, ?AmoClient $amoApi = null): Lead
    {
        $body = self::mappingRows($this->fields_lead);

        if (!$body) {
            return $lead;
        }

        foreach ($body as $field) {
            $amoField = $this->amoField($field['field_amo'] ?? null, 'leads');
            $fieldYc = $field['field_yc'] ?? null;
            $value = $fieldYc ? ($ycFields[$fieldYc] ?? null) : null;

//            self::debugLog('YClients lead field mapping.', [
//                'setting_id' => $this->id,
//                'field_yc' => $fieldYc,
//                'field_amo' => $field['field_amo'] ?? null,
//                'field_name' => $amoField?->name,
//                'value' => $value,
//            ]);

            if (!$amoField) {
                throw new RuntimeException('amoCRM lead field mapping not found: ' . ($field['field_amo'] ?? 'null'));
            }

            $value = self::valueForAmoField($fieldYc, $amoField, $value, $ycFields);
            self::setAmoFieldById($lead, $amoField, $value, $amoApi, 'leads');
        }
        $lead->save();

        return $lead;
    }
}
