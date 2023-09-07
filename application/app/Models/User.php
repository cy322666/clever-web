<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use App\Models\Integrations\Bizon\Setting;
use App\Models\Integrations\Bizon\Webinar;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
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
        'active',
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

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function canImpersonate()
    {
        return $this->is_root;
    }

    public function canBeImpersonated(): bool
    {
        return true;
    }

    public function bizon_settings(): HasOne
    {
        return $this->hasOne(Setting::class);
    }

    public function getcourse_settings(): HasOne
    {
        return $this->hasOne(Integrations\GetCourse\Setting::class);
    }

    public function alfacrm_settings(): HasOne
    {
        return $this->hasOne(Integrations\Alfa\Setting::class);
    }

    public function tilda_settings(): HasOne
    {
        return $this->hasOne(\App\Models\Integrations\Tilda\Setting::class);
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

    public function amocrm_staffs(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function amocrm_statuses(): HasMany
    {
        return $this->hasMany(Status::class);
    }

    public function amocrm_logs(): HasMany
    {
        return $this->hasMany(Log::class);
    }

    public function fields(): HasMany//amocrm TODO
    {
        return $this->hasMany(Field::class);
    }
}
