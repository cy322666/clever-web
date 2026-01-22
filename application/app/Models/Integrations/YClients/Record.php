<?php

namespace App\Models\Integrations\YClients;

use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
        ])->get('https://api.yclients.com/api/v1/companies?my=1');

        foreach ($companies->json()['data'] as $branch) {

            if ($branch['id'] == $this->company_id)

                return $branch['title'] ?? null;
        }

        return null;
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

        return Status::getObject($pStatusId);
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
        if (empty($arrayRequest['services'][0])) {
            return '';
        }

        $titles = collect($arrayRequest['services'])
            ->pluck('title')
            ->filter()
            ->map(fn($title) => '   ' . $title)
            ->implode("\n");

        return "\n" . $titles;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id', 'client_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id', 'id');
    }
}
