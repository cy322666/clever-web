<?php

namespace App\Models\Integrations\GetCourse;

use App\Models\App;
use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Config;
use Ramsey\Uuid\Uuid;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'getcourse_settings';

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

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function forms(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function orders(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'id','setting_id')
            ->where('user_id', $this->user_id);
    }
}
