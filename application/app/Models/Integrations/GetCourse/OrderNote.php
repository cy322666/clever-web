<?php

namespace App\Models\Integrations\GetCourse;

abstract class OrderNote
{
    public static function create(Order $order): string
    {
        $note = [
            "Информация о заказе",
            '----------------------',
            ' - Имя : ' . $order->name,
            ' - Телефон : ' . $order->phone,
            ' - Почта : ' . $order->email,
            ' - Номер заказа : ' . $order->number,
            ' - ID Заказа : ' . $order->order_id,
            ' - Название : ' . $order->positions,
            ' - Стоимость : ' . $order->cost_money,
            ' - Оплачено : ' . $order->payed_money,
            ' - Осталось : ' . $order->left_cost_money,
            ' - Статус заказа : ' . $order->status_order,
            ' - Ссылка : ' . $order->link,
        ];
        return implode("\n", $note);
    }
}
