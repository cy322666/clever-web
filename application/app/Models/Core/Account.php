<?php

namespace App\Models\Core;

use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'code',
        'zone',
        'state',
        'client_id',
        'work',
        'client_secret',
        'referer',
        'expires_in',
        'created_at',
        'token_type',
        'redirect_uri',
        'endpoint',
        'expires_tariff',
    ];

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function staffs(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(Status::class);
    }
}
