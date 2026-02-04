<?php

namespace App\Jobs\ImportExcel;

use App\Models\amoCRM\Field;
use App\Models\Core\Account;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Tags;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

class ProcessImportRow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Account $account;

    public function __construct(
        public int $settingId,
        public int $recordId,
    ) {
        $this->onQueue('import_excel');

        $this->account = ImportSetting::query()->find($settingId)->user->account;
    }

    public function tags(): array
    {
        return ['import-excel', 'client:' . $this->account->subdomain];
    }

    public function handle(): void
    {
        Artisan::call('app:import-excel', [
            'setting_id' => $this->settingId,
            'record_id' => $this->recordId,
        ]);
    }
}
