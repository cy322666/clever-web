<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use Illuminate\Http\Client\ConnectionException;
use Ufee\Amo\Models\Lead as LeadModel;

class Notes
{
    /**
     * @throws ConnectionException
     */
    public static function createNoteLead(YClients $client, Record $record, LeadModel $lead): void
    {
        $note = $lead->createNote();

        $note->text = implode("\n", [
            ' - Запись №'.$record->record_id,
            ' - Событие : '.$record->getEvent(),
            ' - Филиал : '.$client->getBranchTitle($record->company_id),
            ' - Процедуры : '.$record->title,
            ' - Дата и Время : '.$record->datetime,
            ' - Мастер : '.$record->staff_name,
            ' Комментарий : '.$record->comment,
        ]);
        $note->save();
    }
}
