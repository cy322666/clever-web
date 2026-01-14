<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use Illuminate\Http\Client\ConnectionException;
use Ufee\Amo\Models\Lead as LeadModel;
use Vgrish\Yclients\Yclients;

class Notes
{
    /**
     * @throws ConnectionException
     */
//    private function createNoteTextPay(Yclients $client, Record $record): string
//    {
//        return implode("\n", [
//            ' - Событие : Оплачена запись № '.$record->record_id,
//            ' - Филиал : '.$record->getBranchTitle($client),
//            ' - Стоимость : '.$record->cost. ' p',
//            ' Комментарий : '.$record->comment,//TODO коммент записывается?
//        ]);
//    }

    public static function createNoteLead(Yclients $client, Record $record, LeadModel $lead): void
    {
        $note = $lead->createNote();

        $note->text = implode("\n", [
            ' - № Записи '.$record->record_id,
            ' - Событие : '.$record->getEvent(),
            ' - Филиал : '.$record->getBranchTitle($client),
            ' - Процедуры : '.$record->title,
            ' - Дата и Время : '.$record->datetime,
            ' - Мастер : '.$record->staff_name,
            ' Комментарий : '.$record->comment,
        ]);
        $note->save();
    }

//    public function createNoteLeadDelete(Record $record, LeadModel $lead): void
//    {
//        $note = $lead->createNote();
//
//        $note->text = 'Запись № '.$record->record_id.' удалена из YClients';
//        $note->save();
//    }
//
//    /**
//     * @param Record $record
//     * @param LeadModel $lead
//     * @param int|string $action
//     * @return void
//     * @throws ConnectionException
//     */
//    public function createSwitch(Record $record, LeadModel $lead, int|string $action) : void
//    {
//        match ($action) {
//            3 => $this->createNoteLeadDelete($record, $lead),
//            -1, 0, 1, 2 => $this->createNoteLead($record, $lead),
////            9 => $this->createNoteLeadTransaction($transaction, $record, $lead),
//        };
//    }
}
