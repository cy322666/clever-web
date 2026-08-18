<?php

namespace App\Console\Commands\YClients;

use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client as AmoClient;
use App\Services\amoCRM\Models\Contacts;
use App\Services\YClients\YClients;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Ufee\Amo\Models\Contact;

class BackfillContactCategories extends Command
{
    protected $signature = 'yc:backfill-contact-categories
        {user_id : Local user id}
        {--account-id= : Limit records by amoCRM account id}
        {--setting-id= : Limit records by YClients setting id}
        {--company-id= : Limit records by YClients company/branch id}
        {--record-id= : Limit to one YClients record id}
        {--date-column=datetime : Filter/order by datetime, created_at, updated_at, or id}
        {--from= : Process records with selected date column at or after this date/time}
        {--to= : Process records with selected date column at or before this date/time}
        {--limit= : Max records to process}
        {--dry-run : Show records and resolved categories without updating amoCRM}
        {--include-empty : Also send empty category values to amoCRM}';

    protected $description = 'Backfill mapped YClients client category field to amoCRM contacts for booked records.';

    private const YC_FIELD = 'categories';

    private array $amoClients = [];

    private array $ycClients = [];

    private array $settings = [];

    private array $categoryCache = [];

    public function handle(): int
    {
        $dateColumn = $this->dateColumn();

        $query = Record::query()
            ->where('user_id', $this->argument('user_id'))
            ->where('attendance', 0);

        foreach (
            [
                'account-id' => 'account_id',
                'setting-id' => 'setting_id',
                'company-id' => 'company_id',
                'record-id' => 'record_id',
            ] as $option => $column
        ) {
            if ($this->option($option) !== null) {
                $query->where($column, $this->option($option));
            }
        }

        if ($this->option('from') !== null) {
            $query->where($dateColumn, '>=', $this->filterValue($dateColumn, (string)$this->option('from')));
        }

        if ($this->option('to') !== null) {
            $query->where($dateColumn, '<=', $this->filterValue($dateColumn, (string)$this->option('to')));
        }

        if ($this->option('limit') !== null) {
            $query->limit((int)$this->option('limit'));
        }

        $query->orderBy($dateColumn);

        if ($dateColumn !== 'id') {
            $query->orderBy('id');
        }

        $stats = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $this->info('Backfilling YClients client categories to amoCRM contacts...');

        foreach ($query->cursor() as $record) {
            $stats['processed']++;

            try {
                $result = $this->processRecord($record);

                if ($result === 'updated') {
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;

                Log::error('yc:backfill-contact-categories failed for record.', [
                    'record_db_id' => $record->id,
                    'record_id' => $record->record_id,
                    'client_id' => $record->client_id,
                    'account_id' => $record->account_id,
                    'setting_id' => $record->setting_id,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ]);

                $this->error($this->recordLine($record, 'failed') . ' error: ' . $e->getMessage());
            }
        }

        $this->info(sprintf(
            'Done. processed=%d updated=%d skipped=%d failed=%d',
            $stats['processed'],
            $stats['updated'],
            $stats['skipped'],
            $stats['failed'],
        ));

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processRecord(Record $record): string
    {
        $setting = $this->setting((int)$record->setting_id);

        if (!$setting) {
            $this->line($this->recordLine($record, 'skipped-no-setting'));

            return 'skipped';
        }

        if (!$setting->hasContactFieldMapping(self::YC_FIELD)) {
            $this->line($this->recordLine($record, 'skipped-no-category-mapping'));

            return 'skipped';
        }

        $client = $record->scopedClient();
        $contactId = (int)($client?->contact_id ?? 0);

        if ($contactId <= 0) {
            $this->line($this->recordLine($record, 'skipped-no-contact'));

            return 'skipped';
        }

        $category = $this->category($setting, $record);

        if (blank($category) && !$this->option('include-empty')) {
            $this->line($this->recordLine($record, 'skipped-empty-category', $contactId, $category));

            return 'skipped';
        }

        if ($this->option('dry-run')) {
            $this->line($this->recordLine($record, 'dry-run', $contactId, $category));

            return 'skipped';
        }

        $this->updateContactWithRetry(
            $setting,
            $this->amoApi((int)$record->account_id),
            $contactId,
            [self::YC_FIELD => $category],
            $record
        );

        $this->line($this->recordLine($record, 'updated', $contactId, $category));

        return 'updated';
    }

    private function recordLine(Record $record, string $status, ?int $contactId = null, ?string $category = null): string
    {
        return sprintf(
            '[%s] record_db_id=%d record_id=%s company_id=%s client_id=%s contact_id=%s datetime=%s category=%s',
            $status,
            $record->id,
            $record->record_id,
            $record->company_id,
            $record->client_id,
            $contactId ?: '-',
            $record->datetime ?: '-',
            $this->shortValue($category),
        );
    }

    private function shortValue(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }

        $value = trim((string)$value);

        if (function_exists('mb_strlen') && mb_strlen($value) > 80) {
            return mb_substr($value, 0, 77) . '...';
        }

        return strlen($value) > 80 ? substr($value, 0, 77) . '...' : $value;
    }

    private function setting(int $settingId): ?Setting
    {
        if (!array_key_exists($settingId, $this->settings)) {
            $this->settings[$settingId] = Setting::query()->find($settingId);
        }

        return $this->settings[$settingId];
    }

    private function category(Setting $setting, Record $record): ?string
    {
        $key = implode(':', [
            $setting->id,
            $record->company_id,
            $record->client_id,
        ]);

        if (!array_key_exists($key, $this->categoryCache)) {
            $this->categoryCache[$key] = Setting::YCGetClientCategories($this->ycApi($setting), $record);
        }

        return $this->categoryCache[$key];
    }

    private function amoApi(int $accountId): AmoClient
    {
        if (!isset($this->amoClients[$accountId])) {
            $account = Account::query()->findOrFail($accountId);
            $this->amoClients[$accountId] = (new AmoClient($account))->init();
        }

        return $this->amoClients[$accountId];
    }

    private function ycApi(Setting $setting): YClients
    {
        if (!isset($this->ycClients[$setting->id])) {
            $this->ycClients[$setting->id] = new YClients($setting);
        }

        return $this->ycClients[$setting->id];
    }

    /**
     * @throws Throwable
     */
    private function updateContactWithRetry(
        Setting $setting,
        AmoClient $amoApi,
        int $contactId,
        array $ycFields,
        Record $record,
        int $maxAttempts = 5
    ): void {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $contact = Contacts::get($amoApi, $contactId);

                if (!$contact instanceof Contact) {
                    throw new RuntimeException('amoCRM contact not found: ' . $contactId);
                }

                $setting->YCSetContactFields($contact, $ycFields, [self::YC_FIELD]);

                return;
            } catch (Throwable $e) {
                if (!$this->isAmoLastModifiedConflict($e) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                Log::warning('yc:backfill-contact-categories amoCRM contact update conflict, retrying.', [
                    'record_db_id' => $record->id,
                    'record_id' => $record->record_id,
                    'contact_id' => $contactId,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                ]);

                usleep(250000 * $attempt);
            }
        }
    }

    private function isAmoLastModifiedConflict(Throwable $e): bool
    {
        return stripos($e->getMessage(), 'Last modified date is older than in database') !== false
            || stripos($e->getMessage(), 'Last modified date is older than in.') !== false;
    }

    private function dateColumn(): string
    {
        $column = (string)$this->option('date-column');
        $allowed = ['datetime', 'created_at', 'updated_at', 'id'];

        if (!in_array($column, $allowed, true)) {
            $this->warn('Unsupported --date-column=' . $column . ', using datetime.');

            return 'datetime';
        }

        return $column;
    }

    private function filterValue(string $dateColumn, string $value): mixed
    {
        return $dateColumn === 'id' ? (int)$value : Carbon::parse($value);
    }
}
