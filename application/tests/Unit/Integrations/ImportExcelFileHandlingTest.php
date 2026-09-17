<?php

namespace Tests\Unit\Integrations;

use App\Filament\Resources\Integrations\ImportExcel\ImportResource;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportExcelFileHandlingTest extends TestCase
{
    public function test_exports_disk_uses_its_public_subdirectory(): void
    {
        $this->assertSame(
            rtrim((string) config('app.url'), '/').'/storage/table/format',
            config('filesystems.disks.exports.url'),
        );
    }

    public function test_it_reads_headers_from_the_first_file_row(): void
    {
        Storage::fake('exports');
        Storage::disk('exports')->put(
            'customers.csv',
            "Имя,Телефон,Комментарий\nИван,+79990000000,Тест\n",
        );

        $this->assertSame(
            ['Имя', 'Телефон', 'Комментарий'],
            ImportResource::extractHeadersFromFileState('customers.csv'),
        );
    }
}
