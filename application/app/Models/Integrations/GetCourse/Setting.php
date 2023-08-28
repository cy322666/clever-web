<?php

namespace App\Models\Integrations\GetCourse;

use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function webhooks()
    {
        return $this->hasMany(Webhook::class);
    }

    public function forms()
    {
        return $this->belongsTo(Form::class);
    }

    public function orders()
    {
        return $this->belongsTo(Order::class);
    }

    public function createWebhooks(User $user)
    {
        $this->webhooks()->create([
            'user_id'  => $user->id,
            'app_name' => 'getcourse',
            'app_id'   => 3,
            'active'   => true,
            'path'     => 'getcourse.api.form',
            'type'     => 'status_form',
            'platform' => 'getcourse',
            'uuid'     => Uuid::uuid4(),
            'params'   => Config::get('services.getcourse.wh_form_params')
        ]);

        $this->webhooks()->create([
            'user_id'  => $user->id,
            'app_name' => 'getcourse',
            'app_id'   => 3,
            'active'   => true,
            'path'     => 'getcourse.api.order',
            'type'     => 'status_order',
            'platform' => 'getcourse',
            'uuid'     => Uuid::uuid4(),
            'params'   => Config::get('services.getcourse.wh_order_params')
        ]);
    }
}
