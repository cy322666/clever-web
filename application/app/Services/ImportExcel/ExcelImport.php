<?php

namespace App\Services\ImportExcel;

use App\Jobs\ImportExcel\ProcessImportRow;
use App\Models\Integrations\ImportExcel\ImportRecord;
use App\Models\Integrations\ImportExcel\ImportSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

class ExcelImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    public function __construct(
        public ImportSetting $setting,
//        public ?ImportRecord $importRecord = null
    )
    {
        // Оставляем заголовки как в файле (кириллица), без преобразования в slug/латиницу
        HeadingRowFormatter::default(HeadingRowFormatter::FORMATTER_NONE);
    }

    public function chunkSize(): int
    {
        return 200; // Файл будет читаться кусками по 200 строк
    }

    public function collection(Collection $rows): void
    {
        $userId = $this->setting->user->id;
        $filename = explode('.', $this->setting->file_path)[0];
        $dataToInsert = [];

        foreach ($rows as $row) {
            $rowData = $row instanceof Collection ? $row->toArray() : (array)$row;

            if (collect($rowData)->filter(fn($val) => !is_null($val) && $val !== '')->isEmpty()) {
                continue;
            }

            $dataToInsert[] = [
                'import_id' => $this->setting->id,
                'user_id' => $userId,
                'filename' => $filename,
                'status' => ImportRecord::STATUS_PROCESSING,
                'row_data' => json_encode($rowData), // Важно, если в БД тип json
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Чтобы не переполнить память даже массивом, вставляем пачками по 500 строк
            if (count($dataToInsert) >= 200) {
                ImportRecord::query()->insert($dataToInsert);
                $dataToInsert = [];
            }
        }

        if (!empty($dataToInsert)) {
            ImportRecord::query()->insert($dataToInsert);
        }
    }

    public function headingRow(): int
    {
        return 1;
    }
}
