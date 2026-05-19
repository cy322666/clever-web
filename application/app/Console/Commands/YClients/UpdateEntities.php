<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\YClients\YClients;
use Illuminate\Console\Command;

class UpdateEntities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'yc:update-entities {record_id} {account_id} {setting_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     *
     * + день рождения
     * + эл. почту
     * + категории клиента
     * + пол
     * + скидку
     * + комментарий (в YC это "Примечание" в карточке клиента)
     * + отметку о том, отправлять поздравление в ДР или нет
     * + отметку о том, исключен клиент из рассылки или нет.
     *
     * @return int
     * @throws \Exception
     */
    public function handle()
    {
        $recordId  = $this->argument('record_id');
        $accountId = $this->argument('account_id');
        $settingId = $this->argument('setting_id');

        $record  = Record::query()->findOrFail($recordId);
        $account = Account::query()->findOrFail($accountId);
        $setting = Setting::query()->findOrFail($settingId);
        $client = $record->scopedClient();

        if (!$client) {
            return self::FAILURE;
        }

        $amoApi = (new Client($account))->init();

        try {
            if ($setting->fields_contact || $setting->fields_lead) {
                $yc = new YClients($setting);

                //заполненные поля с ключами
                $arrayFields = Setting::YCGetFields($yc, $record);

                if ($setting->fields_contact) {
                    $contact = Contacts::get($amoApi, $client->contact_id);

                    if ($contact) {
                        $setting->YCSetContactFields($contact, $arrayFields);
                    }
                }

                if ($setting->fields_lead) {
                    $lead = Leads::get($amoApi, $record->lead_id);

                    $setting->YCSetLeadFields($lead, $arrayFields);
                }
            }
        } catch (\Throwable $e) {
            $this->failRecord($record, 'yc:update-entities failed.', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw $e;
        }

//  $note = $contact->createNote();
//  $note->text = $clientYC->object()->getComment();
//  $note->element_type = 1;
//  $note->element_id = $contact->id;
//  $note->save();

    }

    private function failRecord(Record $record, string $message, array $context = []): void
    {
        $record->status = Record::STATUS_FAILED;

        if (blank($record->error_message)) {
            $record->error_message = $this->formatErrorMessage($message, $context);
        }

        $record->save();
    }

    private function formatErrorMessage(string $message, array $context = []): string
    {
        $lines = [$message];

        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $lines[] = $key . ': ' . $value;
        }

        return implode("\n", $lines);
    }
}
