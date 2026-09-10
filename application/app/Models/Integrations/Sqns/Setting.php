<?php

namespace App\Models\Integrations\Sqns;

use App\Filament\Resources\Integrations\Sqns\SqnsResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\amoCRM\Staff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Setting extends Model
{
    use SettingRelation;

    private const STATUS_MAPPING_FIELDS = [
        'status_id_cancel',
        'status_id_wait',
        'status_id_came',
        'status_id_confirm',
        'status_id_delete',
    ];

    protected $table = 'sqns_settings';

    public static string $resource = SqnsResource::class;

    public static array $cost = [
        '1_month' => '2 990 ₽',
        '6_month' => '14 900 ₽',
        '12_month' => '24 900 ₽',
    ];

    protected $fillable = [
        'active',
        'user_id',
        'account_id',
        'api_base_url',
        'email',
        'password',
        'token',
        'webhook_secret',
        'webhook_key',
        'organization_id',
        'organization_name',
        'pipelines',
        'status_id_cancel',
        'status_id_wait',
        'status_id_came',
        'status_id_confirm',
        'status_id_delete',
        'default_responsible_user_id',
        'fields_contact',
        'fields_lead',
        'connected_at',
        'last_synced_at',
        'last_error',
    ];

    protected $hidden = [
        'password',
        'token',
        'webhook_secret',
        'webhook_key',
    ];

    protected $casts = [
        'active' => 'boolean',
        'password' => 'encrypted',
        'token' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'pipelines' => 'array',
        'fields_contact' => 'array',
        'fields_lead' => 'array',
        'connected_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $setting): void {
            $setting->webhook_key = $setting->webhook_key ?: Str::random(48);
        });
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class, 'setting_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'setting_id');
    }

    public function webhookUrl(): string
    {
        return route('sqns.hook', [
            'user' => $this->user?->uuid,
            'key' => $this->webhook_key,
        ]);
    }

    public function responsibleUserId(): ?int
    {
        $staffId = (int) $this->default_responsible_user_id;

        if ($staffId <= 0) {
            return null;
        }

        return Staff::query()
            ->where('user_id', $this->user_id)
            ->where('staff_id', $staffId)
            ->where('active', true)
            ->exists()
            ? $staffId
            : null;
    }

    public function isReadyToSync(): bool
    {
        if (blank($this->token)) {
            return false;
        }

        foreach (self::STATUS_MAPPING_FIELDS as $field) {
            if (! preg_match('/^[1-9]\d*\.[1-9]\d*$/', (string) $this->getAttribute($field))) {
                return false;
            }
        }

        return true;
    }

    public static function sourceFieldOptions(): array
    {
        return [
            'visit_id' => 'ID визита',
            'visit_datetime' => 'Дата и время визита',
            'visit_date' => 'Дата визита',
            'visit_time' => 'Время визита',
            'attendance' => 'Статус визита',
            'services' => 'Услуги',
            'cost' => 'Стоимость',
            'total_price' => 'Стоимость без скидки',
            'total_cost' => 'Итоговая стоимость',
            'commodities' => 'Товары',
            'subscriptions' => 'Абонементы',
            'certificates' => 'Сертификаты',
            'resource_id' => 'ID ресурса',
            'master_requested' => 'Клиент выбрал специалиста',
            'author' => 'Автор записи',
            'organization' => 'Организация',
            'online' => 'Онлайн-запись',
            'is_paid' => 'Оплачено',
            'comment' => 'Комментарий',
            'create_date' => 'Дата создания',
            'update_date' => 'Дата изменения',
            'client_id' => 'ID клиента',
            'client_name' => 'Имя клиента',
            'client_phone' => 'Телефон клиента',
            'client_additional_phone' => 'Дополнительный телефон клиента',
            'client_email' => 'Email клиента',
            'client_birth_date' => 'Дата рождения клиента',
            'client_sex' => 'Пол клиента',
            'client_tags' => 'Теги клиента',
            'client_comment' => 'Комментарий клиента',
            'client_type' => 'Тип клиента',
            'client_address' => 'Адрес клиента',
            'client_visits_count' => 'Количество визитов клиента',
            'client_total_arrival' => 'Сумма посещений клиента',
        ];
    }
}
