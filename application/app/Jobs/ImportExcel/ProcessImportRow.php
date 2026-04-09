<?php

namespace App\Jobs\ImportExcel;

use App\Models\Core\Account;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class ProcessImportRow implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;
    public int $tries = 2;
    public int $uniqueFor = 900;

    public ?Account $account = null;

    public function __construct(
        public int $settingId,
        public int $recordId,
    ) {
        $this->onQueue('import_excel');

        $setting = ImportSetting::query()
            ->find($settingId);

        $this->account = $setting?->amoAccount(false, 'import-excel');
    }

    public function tags(): array
    {
        return [
            'import-excel',
            'client:' . ($this->account?->subdomain ?? 'unknown'),
        ];
    }

    public function uniqueId(): string
    {
        return "import-excel:{$this->settingId}:{$this->recordId}";
    }

    public function handle(): void
    {
        $record = ImportRecord::query()->find($this->recordId);

        if (!$record || $record->status === ImportRecord::STATUS_COMPLETED) {
            return;
        }

        Artisan::call('app:import-excel', [
            'setting_id' => $this->settingId,
            'record_id' => $this->recordId,
        ]);
    }
}
