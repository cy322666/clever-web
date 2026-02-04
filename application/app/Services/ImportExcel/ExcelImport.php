<?php

namespace App\Services\ImportExcel;

use App\Jobs\ImportExcel\ProcessImportRow;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

class ExcelImport implements ToCollection, WithHeadingRow
{
    public function __construct(
        public ImportSetting $setting,
//        public ?ImportRecord $importRecord = null
    )
    {
        // Оставляем заголовки как в файле (кириллица), без преобразования в slug/латиницу
        HeadingRowFormatter::default(HeadingRowFormatter::FORMATTER_NONE);
    }

    public function collection(Collection $rows): void
    {
//        $totalRows = $rows->count();

//        $importId = $this->setting->id.Carbon::now()->timestamp;

        $userId = $this->setting->user->id;

        $filename = explode('.', explode('/', $this->setting->file_path)[1])[0] ?? $this->setting->file_path;

        foreach ($rows as $row) {
            $rowData = is_array($row) ? $row : (is_object($row) && method_exists($row, 'toArray') ? $row->toArray(
            ) : (array)$row);

            $import = ImportRecord::query()
                ->create([
                    'import_id' => $this->setting->id,
                    'user_id' => $userId,
                    'filename' => $filename,
                    'status' => ImportRecord::STATUS_PROCESSING,
                    'row_data' => $rowData,
                ]);
            // ProcessImportRow::dispatch($this->setting->id, $import->id);
        }

        // Обновляем общее количество строк
//        if ($this->importRecord) {
//            $this->importRecord->update([
//                'total_rows' => $totalRows,
//            ]);
//        }

//        foreach ($rows as $row) {
//            // Отправляем каждую строку в очередь для обработки
//
//        }
    }

    public function headingRow(): int
    {
        return 1;
    }
}
