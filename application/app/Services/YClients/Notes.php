<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client;
use Illuminate\Http\Client\ConnectionException;
use Ufee\Amo\Models\Lead as LeadModel;

class Notes
{
    /**
     * @throws ConnectionException
     */
    public static function createNoteLead(YClients $client, Record $record, LeadModel $lead, Client $amoApi): void
    {
        $note = $amoApi->service->notes()->create();

        $note->element_id = $lead->id;
        $note->element_type = 2;
        $note->note_type = 4;
        $note->text = implode("\n", [
            ' - Запись №' . $record->record_id,
            ' - Событие : ' . $record->getEvent(),
            ' - Филиал : ' . $client->getBranchTitle($record->company_id),
            ' - Процедуры : ' . $record->title,
            ' - Дата и Время : ' . $record->datetime,
            ' - Мастер : ' . $record->staff_name,
            ' Комментарий : ' . $record->comment,
        ]);
        $note->save();
    }
}
