<?php

namespace App\Models\Integrations\GetCourse;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

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
        'order_status',
        'status',
        'link',
        'status_order',
        'webhook_id',
        'user_id',
        'lead_id',
        'contact_id',
        'error',
    ];

    public function text(): string
    {
        $note = [
            "Информация о заказе",
            '----------------------',
            ' - Имя : ' . $this->name,
            ' - Телефон : ' . $this->phone,
            ' - Почта : ' . $this->email,
            ' - Номер заказа : ' . $this->number,
            ' - ID Заказа : ' . $this->order_id,
            ' - Название тарифа : ' . $this->positions,
            ' - Стоимость тарифа : ' . $this->cost_money,
            ' - Оплачено : ' . $this->payed_money,
            ' - Осталось заплатить : ' . $this->left_cost_money,
            ' - Статус заказа : ' . $this->status_order,
            ' - Ссылка : ' . $this->link,
        ];
        return implode("\n", $note);
    }
}
