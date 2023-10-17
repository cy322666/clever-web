<?php

namespace App\Console\Commands\Docs;

use App\Models\amoCRM\Field;
use App\Models\Core\Account;
use App\Models\Integrations\Docs\Doc;
use App\Models\Integrations\Docs\Setting;
use App\Services\amoCRM\Client;
use App\Services\Doc\DiskService;
use Aspose\Words\ApiException;
use Aspose\Words\Model\PdfSaveOptionsData;
use Aspose\Words\Model\Requests\ConvertDocumentRequest;
use Aspose\Words\Model\Requests\DownloadFileRequest;
use Aspose\Words\Model\Requests\SaveAsRequest;
use Aspose\Words\Model\Requests\UploadFileRequest;
use Aspose\Words\Model\StorageFile;
use Aspose\Words\WordsApi;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mackey\Yandex\Disk;
use Mackey\Yandex\Exception\AlreadyExistsException;
use Mackey\Yandex\Exception\NotFoundException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;

class Generate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:doc-generate {doc} {account} {setting}';

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
        $doc     = Doc::find($this->argument('doc'));
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

        $filename = $settingRaw['name_form'].'-'.Carbon::now()->format('Y-m-d');

        $localPath = DiskService::getLocalPath().$account->subdomain;

        DiskService::checkLocalDirectory($localPath);

        $document->saveAs($localPath.'/'.$filename.'.docx');

        $disk = new Disk(\env('YANDEX_DISK_TOKEN')); // TODO

        $uploadPath = Config::get('services.yandex.yandex_storage_path').$account->subdomain;

        if ($settingRaw['format'] == 'pdf') {

            $api = new WordsApi(Env::get('ASPOSE_CLIENT_ID'), Env::get('ASPOSE_SECRET'));

            $upload_request = new UploadFileRequest($localPath.'/'.$filename.'.docx', $filename);
            $api->uploadFile($upload_request);

            $saveOptions = new PdfSaveOptionsData(array("file_name" => $filename.'.pdf'));
            $request = new SaveAsRequest($filename, $saveOptions);
            $api->saveAs($request);

            $request = new DownloadFileRequest($filename.'.pdf');
            $resultD = $api->downloadFile($request);

            file_put_contents($localPath.'/'.$filename.'.pdf', $resultD->fread($resultD->getSize()));
        }

        $disk->resource($filename)->upload($localPath.'/'.$filename.'.'.$settingRaw['format'], true);

        $disk->resource($filename)->move($uploadPath.'/'.$filename.'.'.$settingRaw['format'], true);

        $resource = $disk->resource($uploadPath.'/'.$filename.'.'.$settingRaw['format'])->publish();

        $linkField = Field::query()->find($settingRaw['field_amo']);

        $lead->cf($linkField->name)->setValue($resource->public_url);
        $lead->save();

        $doc->filename = $resource->public_url;
        $doc->status = 1;
        $doc->save();
    }
}
