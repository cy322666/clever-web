<?php

namespace App\Console\Commands\CallTranscription;

use App\Models\amoCRM\Field;
use App\Models\Core\Account;
use App\Models\Integrations\CallTranscription\Setting;
use App\Models\Integrations\CallTranscription\Transaction;
use App\Models\Integrations\Dadata\Lead;
use App\Services\Ai\IamTokenService;
use App\Services\Ai\YandexGptService;
use App\Services\Ai\YandexSpeechkitService;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use Dadata\DadataClient;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Ufee\Amo\Models\Contact;

class CallSend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:call-send {transaction_id} {account_id} {setting_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle()
    {
        $transaction = Transaction::query()->find($this->argument('transaction_id'));
        $account = Account::query()->find($this->argument('account_id'));
        $setting = Setting::query()->find($this->argument('setting_id'));

        $settingBody = json_decode($setting->settings, true)[$transaction->form_setting_id];

        $amoApi = new Client($account);

        $iamToken = app(IamTokenService::class)->getToken();

        $folderId = 'b1g3cek7i5mcra3e8mui';

        $speechkit = new YandexSpeechkitService($iamToken);

        $transcript = $speechkit->transcribeFromUrl($transaction->url);

        $transcriptText = trim((string)$transcript);

        if (!$transcriptText) {
            Log::warning('Транскрипция пуста. Проверьте распознавание речи.');

            return;
        }

        Log::warning('CallSend transcript debug', [
            'len' => strlen($transcriptText),
            'preview' => substr($transcriptText, 0, 200),
        ]);

        $ai = new YandexGptService();
        $ai->iamToken = $iamToken;
        $ai->folderId = $folderId;

        // $amoApi = (new Client($account))->init();

        // $contact = $amoApi->service->contacts()->find($transaction->contact_id);

        $result = $ai->generate(trim($settingBody['prompt'] ?? ''), $transcriptText);

        $transaction->text = $transcriptText;
        $transaction->result = $result;
        $transaction->save();


//        $amoApi->service->leads()->find($data['entity_id']);

//        if (!$entity) {
//            return new Response(null, 404);
//        }

        // if (($selectedSetting['result_destination'] ?? 'field') === 'note') {
        //     $notePrefix = trim($selectedSetting['note_prefix'] ?? '');
        //     $noteText = $notePrefix ? $notePrefix . "\n" . $result : $result;

        // if ($field) {
        //     $contact->cf($field->name)->setValue($result);
        //     $contact->save();
        // }

        // Notes::addOne($contact, $noteText);
        // } else {
        //     $field = Field::query()->find($selectedSetting['field_id'] ?? null);

        //     if ($field) {
        //         $contact->cf($field->name)->setValue($result);
        //         $contact->save();
        //     }
        // }


//        if (!empty($selectedSetting['salesbot_id']) && $entityType === 'leads') {
//            $amoApi->service->ajax()->post('/api/v4/leads/'.$data['entity_id'].'/actions/salesbot', [
//                'bot_id' => (int) $selectedSetting['salesbot_id'],
//            ]);
//        }
    }
}
