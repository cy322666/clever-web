<?php

namespace App\Models\Integrations\GetCourse;

use App\Filament\Resources\Integrations\GetCourseResource;
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

    protected $table = 'getcourse_settings';

    public static string $resource = GetCourseResource::class;

    public static string $description = "Интеграция нескольких форм/сайтов/настроек. Поиск и склейка дублей контакта. Возможность склеивать сделки...";

    public static array $cost = [
        '1_month'  => '1.000 р',
        '6_month'  => '5.000 р',
        '12_month' => '10.000 р',
    ];

    protected $fillable = [
        'user_id',
        'status_id_form',
        'status_id_order',
        'status_id_order_close',
        'active',
        'response_user_id_default',
        'response_user_id_form',
        'response_user_id_order',
        'tag_order',
        'tag_form',
    ];

    public function forms(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function orders(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
