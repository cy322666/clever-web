<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Core\Account;
use App\Models\Integrations\Bizon\Setting;
use App\Models\Integrations\Bizon\Webinar;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'uuid',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function bizon_settings(): HasOne
    {
        return $this->hasOne(Setting::class);
    }

    public function getcourse_settings(): HasOne
    {
        return $this->hasOne(Integrations\GetCourse\Setting::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(Account::class);
    }

    public function webinars(): HasMany
    {
        return $this->hasMany(Webinar::class);
    }

    public function apps(): HasMany
    {
        return $this->hasMany(App::class);
    }
}
