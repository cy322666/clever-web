<?php

namespace App\Models\Integrations\GetCourse;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Form extends Model
{
    use HasFactory;

    protected $table = 'getcourse_forms';

    protected $fillable = [
        'email',
        'phone',
        'name',
        'status',
        'webhook_id',
        'user_id',
        'lead_id',
        'contact_id',
        'error',
    ];

    public function text(): string
    {
        $note = [
            "Информация о заявке",
            '----------------------',
            ' - Имя : ' . $this->name,
            ' - Телефон : ' . $this->phone,
            ' - Почта : ' . $this->email,
        ];
        return implode("\n", $note);
    }
}
