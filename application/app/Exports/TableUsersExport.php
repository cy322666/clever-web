<?php

namespace App\Exports;

use App\Models\Integrations\Table\User;
use Maatwebsite\Excel\Concerns\FromCollection;

class TableUsersExport implements FromCollection
{
    public int $setting_id;
    public string $filename;

    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return User::all()
            ->where('table_setting_id', $this->setting_id)
            ->collect();
    }
}
