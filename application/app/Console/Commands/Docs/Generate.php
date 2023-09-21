<?php

namespace App\Console\Commands\Docs;

use App\Models\Core\Account;
use App\Models\Integrations\Docs\Doc;
use App\Models\Integrations\Docs\Setting;
use App\Services\amoCRM\Client;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use PhpOffice\PhpWord\TemplateProcessor;

class Generate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate {doc} {account} {setting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $doc    = Doc::find($this->argument('doc'));
        $account = Account::find($this->argument('account'));
        $setting = Setting::find($this->argument('setting'));

        $settingRaw = json_decode($setting->settings, true);
        $settingRaw = $doc->doc_id ? $settingRaw[$doc->doc_id] : $settingRaw[0];

        $amoApi = (new Client($account))
            ->setDelay(0.5)
            ->initLogs(Env::get('APP_DEBUG'));

        $lead = $amoApi->service->leads()->find($doc->lead_id);

        $contact = $lead->contact;

        $document = new TemplateProcessor(storage_path('app/public/'.$settingRaw['template']));

        $document = Doc::generate($document->getVariables(), $document, [
            'leads'    => $lead,
            'contacts' => $contact,
        ]);

        $filename = 'test.docx';


//        $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($document, 'Word2007');

        $document->saveAs(storage_path('app/public/'.$account->subdomain.'/'.$filename));
    }
}
