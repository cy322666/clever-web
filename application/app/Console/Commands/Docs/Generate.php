<?php

namespace App\Console\Commands\Docs;

use App\Models\amoCRM\Field;
use App\Models\Core\Account;
use App\Models\Integrations\Docs\Doc;
use App\Models\Integrations\Docs\Setting;
use App\Services\amoCRM\Client;
use App\Services\Doc\DiskService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Mackey\Yandex\Disk;
use Mackey\Yandex\Exception\AlreadyExistsException;
use Mackey\Yandex\Exception\NotFoundException;
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
     * @throws \Exception
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

        $document = new TemplateProcessor(DiskService::getLocalPath().$settingRaw['template']);

        $document = Doc::generate($document->getVariables(), $document, [
            'leads'    => $lead,
            'contacts' => $contact,
        ]);

        $filename = $settingRaw['name_form'].'-'.Carbon::now()->format('Y-m-d').'.docx';

//        $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($document, 'Word2007');

        $localPath = DiskService::getLocalPath().$account->subdomain;

        DiskService::checkLocalDirectory($localPath);

        $document->saveAs($localPath.'/'.$filename);

        $disk = new Disk(\env('YANDEX_DISK_TOKEN')); // TODO

        $uploadPath = Config::get('services.yandex.yandex_storage_path').$account->subdomain;

        $disk->resource($filename)->upload($localPath.'/'.$filename, true);

        $disk->resource($filename)->move($uploadPath.'/'.$filename, true);

        $resource = $disk->resource($uploadPath.'/'.$filename)->publish();

        $linkField = Field::query()->find($settingRaw['field_amo']);

        $lead->cf($linkField->name)->setValue($resource->public_url);
        $lead->save();

        $setting->filename = $resource->public_url;
        $setting->status = 1;
        $setting->save();

        // проверить сущестует такой файл на диске ?
        // вернет, например, false

        // загрузить файл на диск под имененм "новый файл.txt".

        // файл загружен, вывести информацию.
    }
}
