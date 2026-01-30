<?php

namespace App\Imports\amoCRM;

use App\Jobs\amoCRM\ProcessImportRow;
use App\Models\Integrations\amoCRM\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ExcelImport implements ToCollection, WithHeadingRow
{
    public function __construct(
        public ImportSetting $setting,
        public ?ImportRecord $importRecord = null
    ) {
    }

    public function collection(Collection $rows): void
    {
        $totalRows = $rows->count();

        // Обновляем общее количество строк
        if ($this->importRecord) {
            $this->importRecord->update([
                'total_rows' => $totalRows,
            ]);
        }

        foreach ($rows as $row) {
            // Отправляем каждую строку в очередь для обработки
            ProcessImportRow::dispatch($this->setting, $row->toArray(), $this->importRecord?->id);
        }
    }

    public function headingRow(): int
    {
        return 1;
    }
}
