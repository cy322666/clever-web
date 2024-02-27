<?php

namespace App\Models\Integrations\GetCourse;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    const STATUS_WAIT = 0;
    const STATUS_OK   = 1;
    const STATUS_FAIL = 2;

    protected $table = 'getcourse_orders';

    protected $fillable = [
        'phone',
        'email',
        'name',
        'number',
        'order_id',
        'positions',
        'left_cost_money',
        'cost_money',
        'payed_money',
        'link',
        'status',
        'status_order',
        'user_id',
        'lead_id',
        'contact_id',
        'user_id',
        'template',
    ];

    public function setting(): HasOne
    {
        return $this->hasOne(Setting::class);
    }
}
