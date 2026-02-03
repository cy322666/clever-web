<?php

namespace App\Services\ImportExcel;

use App\Models\Integrations\Table\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class TableUserImport implements ToModel, WithHeadingRow
{
    public int $setting_id;
    public string $filename;

    /**
     * @param array $row
     *
     * @return Model|User|null
     */
    public function model(array $row): Model|User|null
    {
        try {
            return new User([
                'username' => $row['username'],
                'user_id' => Auth::id(),
                'table_setting_id' => $this->setting_id,
                'body' => json_encode($row),
                'filename' => $this->filename,
            ]);
        } catch (UniqueConstraintViolationException $th) {
        }
    }

    public function headingRow(): int
    {
        return 1;
    }
}
