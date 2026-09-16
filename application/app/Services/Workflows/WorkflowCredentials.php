<?php

namespace App\Services\Workflows;

use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowCredential;
use App\Services\Workflows\Credentials\CredentialProvider;
use App\Services\Workflows\Credentials\TelegramBotCredential;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Leek\FilamentWorkflows\Context\WorkflowContext;

final class WorkflowCredentials
{
    /** Service-node credentials only. amoCRM account connections are managed separately. */
    private const PROVIDERS = ['telegram' => TelegramBotCredential::class];

    public static function providers(): array
    {
        return array_map(fn (string $provider): string => $provider::label(), self::PROVIDERS);
    }

    public static function schema(?string $provider, bool $editing = false): array
    {
        $class = self::PROVIDERS[$provider ?? ''] ?? null;
        return $class ? $class::schema($editing) : [];
    }

    public static function options(string $provider = 'telegram'): array
    {
        if (!Auth::id() || !isset(self::PROVIDERS[$provider])) return [];
        return WorkflowCredential::query()->where('user_id', Auth::id())->where('provider', $provider)
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function save(array $data): int
    {
        abort_unless(Auth::id(), 403);
        Validator::make($data, [
            'provider' => ['required', 'string', Rule::in(array_keys(self::PROVIDERS))],
            'credential_id' => 'nullable|integer|min:1',
            'credentials' => 'required|array',
        ])->validate();
        $provider = $data['provider'];
        $id = $data['credential_id'] ?? null;
        $credential = filled($id)
            ? WorkflowCredential::query()->where('user_id', Auth::id())->where('provider', $provider)->findOrFail($id)
            : new WorkflowCredential(['user_id' => Auth::id(), 'provider' => $provider]);
        /** @var class-string<CredentialProvider> $class */
        $class = self::PROVIDERS[$provider];
        try {
            $prepared = $class::prepare($data['credentials'], $credential->exists);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())
                ->mapWithKeys(fn ($messages, $field) => ['credentials.'.$field => $messages])->all());
        }
        if ($prepared !== null) {
            $credential->name = $prepared['name'];
            $credential->secret = $prepared['secret'];
            $credential->save();
        }
        return (int) $credential->id;
    }

    public static function saveFromForm(array $data, Schema $schema): int
    {
        try { return self::save($data); }
        catch (ValidationException $error) {
            throw ValidationException::withMessages(collect($error->errors())
                ->mapWithKeys(fn ($messages, $field) => [$schema->getStatePath().'.'.$field => $messages])->all());
        }
    }

    public static function token(int $id, ?WorkflowContext $context): string
    {
        // Queue workers have no web session: use the owner of the executing workflow.
        $owner = $context?->getWorkflowId()
            ? Workflow::query()->whereKey($context->getWorkflowId())->value('user_id')
            : $context?->getTriggeredBy();
        $credential = $owner ? WorkflowCredential::query()->where('user_id', $owner)
            ->where('provider', 'telegram')->find($id) : null;
        if (!$credential) throw new \RuntimeException('Подключение Telegram недоступно. Выберите своего бота в настройках ноды.');
        try { return $credential->secret; }
        catch (\Throwable) { throw new \RuntimeException('Не удалось прочитать токен подключения. Сохраните его заново.'); }
    }
}
