<?php

namespace App\Models\Integrations\YClients;

use App\Models\amoCRM\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Vgrish\Yclients\Yclients;

class Record extends Model
{
    protected $fillable = [
        'user_id',
        'account_id',
        'setting_id',
        'record_id',
        'company_id',
        'title',
        'cost',
        'lead_id',
        'staff_id',
        'staff_name',
        'client_id',
        'visit_id',
        'visits',
        'datetime',
        'comment',
        'seance_length',
        'attendance',
        'status',
        'send',
    ];

    protected $table = 'yclients_records';

    public function getEvent(): ?string
    {
        return match ($this->attendance) {
           -1 => 'Клиент не пришел',
            0 => 'Клиент записан',
            1 => 'Клиент пришел',
            2 => 'Клиент подтвердил',
            3 => 'Запись удалена',
        };
    }

    /**
     * @throws ConnectionException
     */
    public function getBranchTitle(Yclients $client): ?string
    {
        $companies = Http::withHeaders([
            'Accept'        => 'Accept: application/vnd.api.v2+json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $client->getPartnerToken().', User '.$client->getUserToken(),
        ])->get('https://api.yclients.com/api/v1/groups');

        $rawBranches = json_decode($companies->getBody()->getContents(), true)['data'];

        //сети -> сеть, внутри филиалы
        foreach ($rawBranches as $branches) {

            foreach ($branches['companies'] as $branch) {

                if ($branch->id == $this->company_id) {

                    return $branch->title;
                }
            }
        }
    }

    public function getStatusId(Setting $setting): ?object
    {
        $pStatusId = match ($this->attendance) {
           -1 => $setting->status_id_cancel,
            0 => $setting->status_id_wait,
            1 => $setting->status_id_came,
            2 => $setting->status_id_confirm,
            3 => $setting->status_id_delete,
        };

        $object = Status::getObject($pStatusId);

        return $object?->status_id;
    }

    public static function sumCostServices(array $arrayRequest): int
    {
        $costSum = 0;

        if(!empty($arrayRequest['services'][0])) {

            foreach ($arrayRequest['services'] as $array) {

                $costSum += $array['cost'];
            }
        }
        return $costSum;
    }

    public static function buildCommentServices(array $arrayRequest): string
    {
        $stringServices = '';

        if(!empty($arrayRequest['services'][0])) {

            foreach ($arrayRequest['services'] as $array) {

                $stringServices .= $array['title'].' |';
            }
            $stringServices = trim($stringServices, ' |', );
        }
        return $stringServices;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }
}
