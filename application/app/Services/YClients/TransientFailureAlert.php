<?php

namespace App\Services\YClients;

use App\Models\Integrations\YClients\Record;

final class TransientFailureAlert
{
    public const DELAY_SECONDS = 180;

    public function __construct(
        public int $recordId,
        public int $userId,
        public int $accountId,
        public int $settingId,
        public string $errorHash,
    ) {}

    public static function isTransient(string $error): bool
    {
        return preg_match('/Invalid API response \(non JSON\), code: 0(?=\s|$)/', $error) === 1
            || str_contains($error, 'Illuminate\\Http\\Client\\ConnectionException');
    }

    public static function forRecord(Record $record): self
    {
        return new self((int) $record->id, (int) $record->user_id, (int) $record->account_id,
            (int) $record->setting_id, hash('sha256', (string) $record->error_message));
    }

    public function key(): string
    {
        return hash('sha256', implode('|', [$this->recordId, $this->userId, $this->accountId,
            $this->settingId, $this->errorHash]));
    }

    public function stillFailed(): bool
    {
        $record = Record::query()->whereKey($this->recordId)
            ->where('user_id', $this->userId)->where('account_id', $this->accountId)
            ->where('setting_id', $this->settingId)->first(['status', 'error_message']);

        return $record !== null && $record->status === Record::STATUS_FAILED
            && hash_equals($this->errorHash, hash('sha256', (string) $record->error_message));
    }
}
