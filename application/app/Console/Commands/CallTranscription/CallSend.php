<?php

namespace App\Console\Commands\CallTranscription;

use App\Models\Core\Account;
use App\Models\Integrations\CallTranscription\Setting;
use App\Models\Integrations\CallTranscription\Transaction;
use App\Services\Ai\DeepSeekService;
use App\Services\Ai\IamTokenService;
use App\Services\Ai\YandexGptService;
use App\Services\Ai\YandexSpeechkitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CallSend extends Command
{
    private const STATUS_SUCCESS = 1;
    private const STATUS_FAILED = 2;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:call-send {transaction_id} {account_id} {setting_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    public function handle(): void
    {
        $transaction = Transaction::query()->find($this->argument('transaction_id'));
        $account = Account::query()->find($this->argument('account_id'));
        $setting = Setting::query()->find($this->argument('setting_id'));

        if (!$transaction || !$account || !$setting) {
            Log::warning('CallSend skipped: transaction/account/setting not found', [
                'transaction_id' => $this->argument('transaction_id'),
                'account_id' => $this->argument('account_id'),
                'setting_id' => $this->argument('setting_id'),
            ]);

            return;
        }

        $settings = json_decode((string)$setting->settings, true);
        $settings = is_array($settings) ? $settings : [];
        $settingBody = $settings[$transaction->form_setting_id] ?? [];

        if (!is_array($settingBody) || $settingBody === []) {
            $this->markFailed($transaction, 'Настройка формы не найдена.');
            Log::warning('CallSend failed: setting body not found', [
                'transaction_id' => $transaction->id,
                'setting_id' => $setting->id,
                'form_setting_id' => $transaction->form_setting_id,
            ]);

            return;
        }

        $provider = (string)($settingBody['ai_provider'] ?? 'yandex');
        $aiToken = trim((string)($settingBody['token'] ?? ''));
        $yandexFolderId = trim((string)($settingBody['folder_id'] ?? config('services.yandex_gpt.folder_id', '')));

        if ($aiToken === '') {
            $tokenType = $provider === 'deepseek' ? 'API key DeepSeek' : 'IAM token Yandex';
            $this->markFailed($transaction, 'Не указан ' . $tokenType . ' в настройке.');
            return;
        }

        if (!in_array($provider, ['yandex', 'deepseek'], true)) {
            $this->markFailed($transaction, 'Неизвестный провайдер ИИ: ' . $provider);
            return;
        }

        if ($provider === 'yandex' && $yandexFolderId === '') {
            $this->markFailed($transaction, 'Не указан Yandex Folder ID в настройке.');
            return;
        }

        try {
            $speechkitIamToken = app(IamTokenService::class)->getToken();
            $speechkit = new YandexSpeechkitService($speechkitIamToken);
            $transcript = $speechkit->transcribeFromUrl($transaction->url);
            $transcriptText = trim((string)$transcript);

            if ($transcriptText === '') {
                $this->markFailed($transaction, 'Транскрипция пуста. Проверьте запись звонка.');
                return;
            }

            $prompt = trim((string)($settingBody['prompt'] ?? ''));
            $result = $this->generateByProvider($provider, $prompt, $transcriptText, $aiToken, $yandexFolderId);

            $transaction->text = $transcriptText;
            $transaction->result = $result;
            $transaction->status = self::STATUS_SUCCESS;
            $transaction->save();
        } catch (Throwable $e) {
            $this->markFailed($transaction, $e->getMessage());
            Log::error('CallSend failed', [
                'transaction_id' => $transaction->id,
                'provider' => $provider,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }


    }

    private function generateByProvider(
        string $provider,
        string $prompt,
        string $transcriptText,
        string $aiToken,
        string $yandexFolderId
    ): string
    {
        if ($provider === 'deepseek') {
            return app(DeepSeekService::class)->generate($prompt, $transcriptText, $aiToken, true);
        }

        $ai = new YandexGptService();
        $ai->iamToken = $aiToken;
        $ai->folderId = $yandexFolderId;

        return $ai->generate($prompt, $transcriptText, true);
    }

    private function markFailed(Transaction $transaction, string $message): void
    {
        $transaction->status = self::STATUS_FAILED;
        $transaction->result = 'Ошибка: ' . trim($message);
        $transaction->save();
    }
}
