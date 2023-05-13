<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GetCourseOrderSend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct() {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $phone = $_GET['phone'] ?? '';
        $email = $_GET['email'] ?? '';
        $name = $_GET['name'] ?? 'Неизвестно';
        $number = $_GET['number'] ?? '';
        $id = $_GET['id'] ??'';
        $positions = $_GET['positions'];
        $left_cost_money = $_GET['left_cost_money'];
        $cost_money = $_GET['cost_money'];
        $payed_money = $_GET['payed_money'];
        $status = $_GET['status'];
        $link = 'https://online.pekarina-zefir.ru/sales/control/deal/update/id/'.$id;

        $note = [
            'Новый Заказ GetCourse!',
            '----------------------',
            ' Имя : '. $name,
            ' Телефон : '. $phone,
            ' Почта : '. $email,
            '----------------------',
            ' Номер заказа : '.$number,
//    ' Идентификатор : '.$id,
            ' Оплачено : '.$payed_money,
            ' Осталось : '.$left_cost_money,
            //' Оплачено : '.$payed_money,
//    ' Номер заказа : '.$number,

            ' Продукт : '. $positions,
            ' Статус : '. $status,
            ' Ссылка : '.$link,
        ];
        $TextNote = implode("\n", $note);

        $contacts = null;

        if($email != '') {
            $contacts = $amoCRM->contacts()->searchByEmail($email);
        }

        if($phone != '' && $contacts == null) {
            $contacts = $amoCRM->contacts()->searchByPhone($phone);
        }

        if($contacts == null || !$contacts->first()) {
            $contact = $amoCRM->contacts()->create();
            $contact->name = $name;
            $contact->cf('Телефон')->setValue($phone, 'Work');
            $contact->cf('Email')->setValue($email);
//    $contact->responsible_user_id = 6277708;
            $contact->save();
        } else {
            $contact = $contacts->first();
            $contact->cf('Телефон')->setValue($phone, 'Work');
            $contact->cf('Email')->setValue($email);
            $contact->save();
            $leads = $contact->leads;

            $leads_array = $leads->toArray();
            foreach ($leads_array as $array) {
                if ($array['status_id'] != 143 and $array['status_id'] != 142) {
                    $lead = $amoCRM->leads()->find($array['id']);
                    break;
                }
            }
        }
        if (empty($lead)) {
            $lead = $contact->createLead();
            $lead->name = 'Новый заказ с GetCourse';

        }

//if(!empty($_GET['type']) && $_GET['type'] == 'eng') {
//
//    $lead->attachTag('eng');
//    $contact->attachTag('eng');
//}

        $lead->attachTag('Заказ');
//$lead->attachTag('Оплата '.date('Y-m-d'));

//if($_GET['payed_money'] > 0)
//    $lead->attachTag('автооплата');

        $cost_money = str_replace(['руб.', ' '], "", $cost_money);
        if ((int)$cost_money !== 0)
            $lead->sale = (int)$cost_money;
        $lead->save();

        if ($_GET['payed_money'] === $_GET['cost_money']) {

            if ($_GET['cost_money'] === '0 руб.') {

                $lead->status_id = 53958366;
                $lead->attachTag('БесплатныйУрок');

//        $task = $lead->createTask($type = 1);
//        $task->text = 'Клиент сделал заказ на бесплатный урок';
//        $task->element_type = 2;
//        $task->created_at = time() + (60 * 60 * 24);
////        $task->complete_till = $task->created_at + (60 * 60 * 12);
//        $task->element_id = $lead->id;
//        $task->save();

            } else
                $lead->status_id = 142;

        } elseif ($_GET['payed_money'] === '0 руб.') {

            $lead->status_id = 53958366;
        } else
            $lead->status_id = 53958370;

//$lead->cf('Тип оплаты')->setValue('GetCourse');
        $lead->save();
        $contact->save();

        $note = $lead->createNote($type = 4);
        $note->text = $TextNote;
        $note->element_type = 2;
        $note->element_id = $lead->id;
        $note->save();
    }
}
