<?php

namespace App\Models\Integrations\Docs;

use App\Filament\Resources\Integrations\DocResource;
use App\Filament\Resources\Integrations\TildaResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\amoCRM\Field;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'doc_settings';

    public static string $resource = DocResource::class;

    public static string $description = "Генерация документов, формирование ссылок и сохранение на Яндекс.Диск...";

    public static array $cost = [
        '1_month'  => '1.000 р',
        '6_month'  => '5.000 р',
        '12_month' => '10.000 р',
    ];

    protected $fillable = [
        'user_id',
        'settings',
        'active',
        'yandex_token',
        'yandex_expires_in',
    ];

    public function amocrm_fields(): HasMany
    {
        return $this->hasMany(Field::class)->where('user_id', Auth::id());
    }
}
