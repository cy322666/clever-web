<?php

namespace App\Models\Integrations\Bizon;

abstract class ViewerNote
{
    public static function create(Viewer $viewer): string
    {
        $finished = $viewer->userFinished ? 'Да ' : 'Нет';

        $note = [
            "Информация о зрителе",
            '----------------------',
            ' - Ник : ' . $viewer->username,
            ' - Телефон : ' . $viewer->phone,
            ' - Почта : ' . $viewer->email,
            ' - Город : ' . $viewer->city,
            ' - Вебинар запустил(а) : '.$viewer->playVideo,
            ' - Присутствовал(а) : ' .$viewer->time. ' мин',
            ' - Когда зашел : '.$viewer->view ?? '-',
            ' - Когда вышел : ' .$viewer->viewTill ?? '-',
            ' - Присутствовал до конца : '.$finished,
            ' - Кликал по банеру : ' .$viewer->clickBanner,
            ' - Кликал по кнопке : ' .$viewer->clickFile,
            '----------------------',
            ' - Метки :',
            '----------------------',
            ' - utm_source : '.$viewer->utm_source ?? null,
            ' - utm_medium : '.$viewer->utm_medium ?? null,
            ' - utm_content : '.$viewer->utm_content ?? null,
            ' - utm_term : '.$viewer->utm_term ?? null,
            ' - utm_campaign : '.$viewer->utm_campaign ?? null,
            ' - utm_referrer : '.$viewer->utm_referrer ?? null,
        ];
        $note = implode("\n", $note);

        if($viewer->newOrder) {

            $noteOrder = [
                " ",
                "Информация о заказе",
                '----------------------',
                ' - ID заказа : ' . $viewer->newOrder,
                ' - Описание заказа : ' . $viewer->orderDetails
            ];
            $noteOrder = implode("\n", $noteOrder);

            $note = $note ."\n". $noteOrder;
        }

        return $note;
    }

    public static function comments(Viewer $viewer): string
    {
        $note = [
            ' - Комментарии : ',
            '----------------------',
            implode("\n    ", json_decode($viewer->commentaries) ?? []),
        ];

        return implode("\n", $note);
    }
}
