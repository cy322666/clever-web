<?php

namespace App\Models\Integrations\Table;

use App\Filament\Resources\Integrations\DocResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\amoCRM\Field;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'table_settings';

    public static string $resource = DocResource::class;

    public static string $description = "Работа с базами в Excel файлах";

    public static array $cost = [
        '1_month'  => '0 р',
        '6_month'  => '0 р',
        '12_month' => '0 р',
    ];

    protected $fillable = [
        'user_id',
        'settings',

    ];

    public function amocrm_fields(): HasMany
    {
        return $this->hasMany(Field::class)->where('user_id', Auth::id());
    }
}
