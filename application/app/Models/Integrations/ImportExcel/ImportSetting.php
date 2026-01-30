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

    public static string $resource = ImportResource::class;
    protected $table = 'import_settings';
    protected $fillable = [
        'active',
        'default_status_id',
        'default_pipeline_id',
        'default_responsible_user_id',
        'default_lead_name',
        'fields_mapping',
        'check_duplicates',
        'update_existing_contacts',
        'update_existing_leads',
        'link_contact_to_company',
        'tag',
        'user_id',
    ];
    protected $casts = [
        'fields_mapping' => 'array',
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
