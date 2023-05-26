<?php

namespace App\Models\Integrations\Bizon;

use App\Models\Core\Account;
use App\Models\Integrations\Bizon\Setting;
use App\Services\amoCRM\Client;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Viewer extends Model
{
    use HasFactory;

    const STATUS_WAIT = 0;
    const STATUS_OK   = 1;
    const STATUS_FAIL = 2;

    protected $table = 'bizon_viewers';

    protected $fillable = [
        'chatUserId',
        'lead_id'   ,
        'note_id'   ,
        'contact_id',
        'time'      ,
        'phone'     ,
        'webinarId' ,
        'view'      ,
        'viewTill'  ,
        'email'     ,
        'username'  ,
        'roomid'    ,
        'url'       ,
        'ip'        ,
        'useragent' ,
        'created'   ,
        'playVideo' ,
        'finished'  ,
        'messages_num',
        'cv'        ,
        'cu1'       ,
        'p1'        ,
        'p2'        ,
        'p3'        ,
        'referer'   ,
        'city'      ,
        'region'    ,
        'newOrder'  ,
        'country'   ,
        'tz'        ,
        'mob'       ,
        'utm_term'  ,
        'utm_campaign',
        'commentaries',
        'clickBanner' ,
        'clickFile',
        'newOrder',
        'orderDetails',
        'type'
    ];

    //возможность сортировки по полям
    protected $allowedSorts = [
        'status',
        'created_at',
        'updated_at',
    ];

    //возможность фильтрация по полям
    protected $allowedFilters = [

    ];

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function getContent()
    {
        return $this->content;
    }

    public static function getType(Setting $setting, ?int $time) : string
    {
        return match (true) {

            $time >= $setting->time_cold &&
            $time <= $setting->time_soft => 'soft',

            $time >= $setting->time_soft => 'hot',

            $time <= $setting->time_soft => 'cold',
        };
    }

    public function getStatusId(Setting $setting) : ?int
    {
        $status_type = 'status_id_'.$this->type;

        return $setting->$status_type ?? null;
    }

    public function getTagType(Setting $setting) : ?string
    {
        if($this->type) {

            $tag_type = 'tag_'.$this->type;
        }
        return $setting->$tag_type ?? null;
    }

    public static function getTime(mixed $viewTill, mixed $view): int
    {
        if($viewTill && $view) {

            return (int)round((((int)$viewTill - (int)$view) / 1000) / 60);
        } else
            return 0;
    }

    public function convertToDate(string $microtime): int
    {
        return (int)round(((int)$microtime / 1000) / 60);
    }
}
