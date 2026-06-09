<?php

namespace App\Models\Integrations\YClients;

use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Vgrish\Yclients\Yclients;

class Record extends Model
{
    public const STATUS_PENDING = '0';
    public const STATUS_SUCCESS = '1';
    public const STATUS_FAILED = 'failed';

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
        'created_user_id',
        'record_from',
        'visit_id',
        'visits',
        'datetime',
        'comment',
        'seance_length',
        'attendance',
        'status',
        'error_message',
        'lead_fields_replay_status',
        'lead_fields_replayed_at',
        'lead_fields_replay_error',
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

    public function scopedClient(): ?Client
    {
        return Client::query()
            ->where('client_id', $this->client_id)
            ->where('company_id', $this->company_id)
            ->where('account_id', $this->account_id)
            ->where('setting_id', $this->setting_id)
            ->where('user_id', $this->user_id)
            ->first();
    }

    public function scopeFailedExport(Builder $query, bool $includePending = false): Builder
    {
        if ($includePending) {
            return $query->where(function (Builder $query): void {
                $query
                    ->where('status', '!=', self::STATUS_SUCCESS)
                    ->orWhereNull('status');
            });
        }

        return $query->where(function (Builder $query): void {
            $query
                ->where('status', self::STATUS_FAILED)
                ->orWhereNotNull('error_message');
        });
    }

    public function leadOwnerRecord(): ?self
    {
        if (empty($this->lead_id)) {
            return null;
        }

        return static::query()
            ->where('lead_id', $this->lead_id)
            ->where('account_id', $this->account_id)
            ->orderBy('id')
            ->first();
    }

    public function isLeadOwnedByAnotherYClientsRecord(): bool
    {
        $owner = $this->leadOwnerRecord();

        if (!$owner) {
            return false;
        }

        return (string)$owner->record_id !== (string)$this->record_id;
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
