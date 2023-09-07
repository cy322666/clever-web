<?php

namespace App\Models\Integrations\Tilda;

use App\Filament\Resources\Integrations\TildaResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\App;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'tilda_settings';

    public static string $resource = TildaResource::class;

    protected $fillable = [
        'bodies',
        'settings',
        'active',
        'user_id',
        'name',
        'email',
        'phone',
    ];

}
