<?php

namespace App\Models\Integrations\ImportExcel;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRecord extends Model
{
    use HasFactory;

    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    protected $table = 'import_records';

    protected $fillable = [
        'import_id',
        'user_id',
        'filename',
        'file_path',
        'total_rows',
        'processed_rows',
        'success_rows',
        'error_rows',
        'status',
        'error_message',
        'row_data',
        'contact_id',
        'lead_id',
        'company_id',
    ];

    protected $casts = [
        'row_data' => 'array',
    ];

    //по столбцу записи в бд получаем значение
    public function getValueForDefaultKey(?string $key)
    {
        if ($key) {
            foreach ($this->row_data as $keyRow => $valueRow) {
                if ($keyRow == $key) {
                    return $valueRow;
                }
            }
        }
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ImportSetting::class, 'import_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
