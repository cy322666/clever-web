<?php

namespace App\Models\Integrations\ImportExcel;

use App\Filament\Resources\Integrations\ImportExcel\ImportResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\App;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportSetting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'import_settings';

    public static string $resource = ImportResource::class;

    public static string $description = "Выгрузка сделок/контактов/компаний из файлов Excel с гибкими настройками";

    public static array $instruction = [
        'Загрузите файл в поддерживаемом формате в форму',
        'Подождите завершение обработки',
        'Настроите соотношение полей столбцов и полей amoCRM',
        'Настройте обязательные параметры',
        'Нажмите Начать импорт',
        'На странице выгрузки можете выбрать выборочные строки для выгрузки',
        'Либо нажать кнопку Выгрузить все',
    ];

    static array $cost = [
        '6_month' => '6.000 р',//TODO сколько стоит
        '12_month' => '12.000 р',
    ];

    protected $fillable = [
        'active',
        'default_status_id',
        'default_pipeline_id',
        'default_responsible_user_id',
        'default_lead_name',
        'default_sale',
        'contact_name',
        'company_name',
        'lead_name',
        'file_path',
        'original_filename',
        'fields_mapping',
        'fields_leads',
        'fields_contacts',
        'fields_companies',
        'check_duplicates',
        'update_existing_contacts',
        'update_existing_leads',
        'link_contact_to_company',
        'tag',
        'user_id',
        'headers',
    ];
    protected $casts = [
        'headers' => 'array',
        'row_data' => 'array',
        'fields_leads' => 'array',
        'fields_contacts' => 'array',
        'fields_companies' => 'array',
        'check_duplicates' => 'boolean',
        'update_existing_contacts' => 'boolean',
        'update_existing_leads' => 'boolean',
        'link_contact_to_company' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function records()
    {
        return $this->hasMany(ImportRecord::class, 'import_id');
    }
}
