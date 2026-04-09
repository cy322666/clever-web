<?php

namespace App\Jobs\ImportExcel;

use App\Models\Integrations\ImportExcel\ImportSetting;
use App\Services\ImportExcel\ExcelImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ParseImportFile implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 1;
    public int $uniqueFor = 600;

    public function __construct(
        public int $settingId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "import-excel:parse:{$this->settingId}";
    }

    public function handle(): void
    {
        $setting = ImportSetting::query()
            ->with('user')
            ->find($this->settingId);

        if (!$setting || !$setting->active || !$setting->file_path) {
            return;
        }

        $path = Storage::disk('exports')->path($setting->file_path);

        if (!is_file($path)) {
            Log::warning(__METHOD__ . ' import file not found', [
                'setting_id' => $setting->id,
                'file_path' => $setting->file_path,
                'resolved_path' => $path,
            ]);
            return;
        }

        Excel::import(new ExcelImport($setting), $path);
    }
}
