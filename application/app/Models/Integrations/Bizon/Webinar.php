<?php

namespace App\Models\Integrations\Bizon;

use App\Models\Core\Account;
use App\Models\Integrations\Bizon\Setting;
use App\Services\amoCRM\Client;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static create(array $toArray)
 */
class Webinar extends Model
{
    use HasFactory;

    protected $table = 'bizon_webinars';

    protected $fillable = [
        'event',
        'roomid',
        'webinarId',
        'room_title',
        'created',
        'group',
        'stat',  //число зрителей
        'len',   //длительность вебинара
        'account_id'
    ];

    //возможность сортировки по полям
    protected array $allowedSorts = [
        'status',
        'created_at',
        'updated_at',
    ];

    //возможность фильтрация по полям
    protected array $allowedFilters = [
        'status',
        'roomid',
        'created_at',
        'room_title',
    ];

    public function viewers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Viewer::class);
    }

    public function account(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function setViewer($user_key, $user_array, Setting $setting, array $commentariesTS): Model
    {
        $viewTill = $user_array['viewTill'] ?? null;
        $view     = $user_array['view'] ?? null;

        $time = Viewer::getTime($viewTill, $view);

        return $this->viewers()->create([

            'chatUserId' => $user_array['chatUserId'],
            'phone'      => $user_array['phone'],
            'webinarId'  => $user_array['webinarId'],
            'view'       => $view ? Carbon::parse()->microsecond($view)->format('Y-m-d H:i:s') : '-',
            'viewTill'   => $viewTill ? Carbon::parse()->microsecond($viewTill)->format('Y-m-d H:i:s') : '-',
            'time'       => $time,
            'email'      => $user_array['email'],
            'username'   => $user_array['username'],
            'roomid'     => $user_array['roomid'],
            'type'       => Viewer::getType($setting, $time),
            'url'        => !empty($user_array['url']) ? mb_strimwidth($user_array['url'], 0, 100, "...") : null,
            'ip'         => $user_array['ip'],
            'useragent'  => $user_array['useragent'] ?? null,
            'created'    => $user_array['created'],
            'playVideo'  => $user_array['playVideo'] == 1 ? 'Да' : 'Нет',
            'finished'   => !empty($user_array['finished']) ? 'Да' : 'Нет',
            'messages_num' => $user_array['messages_num'] ?? 0,
            'cv'         => $user_array['cv'] ?? null,
            'cu1'        => $user_array['cu1'] ?? null,
            'p1'         => $user_array['p1'] ?? null,
            'p2'         => $user_array['p2'] ?? null,
            'p3'         => $user_array['p3'] ?? null,
            'referer'    => $user_array['referer'] ?? null,
            'city'       => $user_array['city'] ?? null,
            'region'     => $user_array['region'] ?? null,
            'country'    => $user_array['country'] ?? null,
            'tz'         => $user_array['tz'] ?? null,
            'utm_source' => $user_array['utm_source'] ?? null,
            'utm_medium' => $user_array['utm_medium'] ?? null,
            'utm_term' => $user_array['utm_term'] ?? null,
            'utm_content' => $user_array['utm_content'] ?? null,
            'utm_campaign' => $user_array['utm_campaign'] ?? null,

            'clickFile'   => count($user_array['buttons']) > 0 ? 'Да' : 'Нет',
            'clickBanner' => count($user_array['banners']) > 0 ? 'Да' : 'Нет',
            'commentaries'=> count($commentariesTS[$user_key]) > 0 ? json_encode($commentariesTS[$user_key]) : null,

            'status' => Viewer::STATUS_WAIT,
        ]);
    }
}
