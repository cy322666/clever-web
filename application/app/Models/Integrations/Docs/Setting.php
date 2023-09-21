<?php

namespace App\Models\Integrations\Docs;

use App\Models\amoCRM\Field;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'doc_settings';

    protected $fillable = [
        'user_id',
        'settings',
        'active',
        'yandex_token',
        'yandex_expires_in',
    ];

    public function amocrm_fields(): HasMany
    {
        return $this->hasMany(Field::class)->where('user_id', Auth::id());
    }
}
