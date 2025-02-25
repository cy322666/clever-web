<?php

namespace App\Console\Commands\Table;

use App\Exports\TableUsersExport;
use App\Imports\TableUserImport;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ParseExcel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:parse-excel {file_path} {user_id} {setting_id} {filename}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->argument('user_id');

        $path = storage_path('app/public/').$this->argument('file_path');

        if (File::exists($path)) {

            $import = new TableUserImport();
            $import->setting_id = $this->argument('setting_id');
            $import->filename = $this->argument('filename');

            try {

                Excel::import($import, $path);

            } catch (UniqueConstraintViolationException $th) {}

            $export = new TableUsersExport();
            $export->setting_id = $this->argument('setting_id');

            Excel::store($export, $import->filename, 'exports');

            return true;

        } else {
            Log::debug('File does not exist', [$path]);
        }
    }
}
