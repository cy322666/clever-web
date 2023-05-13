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
        'user_id',
        'lead_id',
        'contact_id',
        'utm_medium',
        'utm_content',
        'utm_source',
        'utm_term',
        'utm_campaign',
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
