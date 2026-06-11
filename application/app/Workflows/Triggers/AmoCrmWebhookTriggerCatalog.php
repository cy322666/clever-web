<?php

declare(strict_types=1);

namespace App\Workflows\Triggers;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Arr;
use Leek\FilamentWorkflows\Triggers\Contracts\BaseTrigger;

class AmoCrmWebhookTriggerCatalog
{
    /**
     * @return array<int, class-string<BaseTrigger>>
     */
    public static function classes(): array
    {
        return [
            AmoCrmResponsibleLeadTrigger::class,
            AmoCrmResponsibleContactTrigger::class,
            AmoCrmResponsibleCompanyTrigger::class,
            AmoCrmResponsibleCustomerTrigger::class,
            AmoCrmResponsibleTaskTrigger::class,
            AmoCrmRestoreLeadTrigger::class,
            AmoCrmRestoreContactTrigger::class,
            AmoCrmRestoreCompanyTrigger::class,
            AmoCrmAddLeadTrigger::class,
            AmoCrmAddContactTrigger::class,
            AmoCrmAddCompanyTrigger::class,
            AmoCrmAddCustomerTrigger::class,
            AmoCrmAddTalkTrigger::class,
            AmoCrmAddTaskTrigger::class,
            AmoCrmUpdateLeadTrigger::class,
            AmoCrmUpdateContactTrigger::class,
            AmoCrmUpdateCompanyTrigger::class,
            AmoCrmUpdateCustomerTrigger::class,
            AmoCrmUpdateTalkTrigger::class,
            AmoCrmUpdateTaskTrigger::class,
            AmoCrmDeleteLeadTrigger::class,
            AmoCrmDeleteContactTrigger::class,
            AmoCrmDeleteCompanyTrigger::class,
            AmoCrmDeleteCustomerTrigger::class,
            AmoCrmDeleteTaskTrigger::class,
            AmoCrmStatusLeadTrigger::class,
            AmoCrmNoteLeadTrigger::class,
            AmoCrmNoteContactTrigger::class,
            AmoCrmNoteCompanyTrigger::class,
            AmoCrmNoteCustomerTrigger::class,
            AmoCrmAddChatTemplateReviewTrigger::class,
        ];
    }
}

abstract class AmoCrmWebhookTrigger implements BaseTrigger
{
    public static function type(): string
    {
        return 'amocrm-' . str_replace('_', '-', static::eventCode());
    }

    public static function name(): string
    {
        return static::eventName();
    }

    public static function description(): string
    {
        return static::eventDescription();
    }

    public static function icon(): string
    {
        return static::eventIcon();
    }

    public static function color(): string
    {
        return static::eventColor();
    }

    /**
     * @return array<Component>
     */
    public static function configSchema(): array
    {
        return [
            Hidden::make('source')->default('amocrm'),
            Hidden::make('event')->default(static::eventCode()),
            Hidden::make('entity')->default(static::entity()),
            Hidden::make('action')->default(static::action()),

            Placeholder::make('event_info')
                ->label(static::eventName())
                ->content(static::eventDescription() . ' Код события amoCRM: ' . static::eventCode() . '.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'source' => 'amocrm',
            'event' => static::eventCode(),
            'entity' => static::entity(),
            'action' => static::action(),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    public function shouldTrigger(array $config, mixed $subject, array $context = []): bool
    {
        $expectedEvent = $config['event'] ?? static::eventCode();

        if ($this->eventCodeFromContext($subject, $context) === $expectedEvent) {
            return true;
        }

        $payload = $this->payloadFromContext($subject, $context);

        return $this->payloadContainsEvent($payload);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function getContextData(array $config, mixed $subject, array $context = []): array
    {
        $payload = $this->payloadFromContext($subject, $context);
        $item = $this->firstPayloadItem($payload);

        $data = [
            'source' => 'amocrm',
            'event' => static::eventCode(),
            'entity' => static::entity(),
            'action' => static::action(),
            'payload' => $payload,
            'received_at' => now()->toIso8601String(),
        ];

        if ($item !== null) {
            $data['item'] = $item;
            $data[static::action()] = $item;

            if (static::action() === 'note') {
                $data['note'] = $item;
                $data[static::entity()] = array_replace($item, [
                    'id' => Arr::get($item, 'element_id', Arr::get($item, 'id')),
                ]);
            } else {
                $data[static::entity()] = $item;
            }

            if (static::entity() === 'chat_template_review') {
                $data['chat_template_review'] = $item;
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function getConfiguredDescription(array $config): string
    {
        return static::eventDescription() . ' <strong>' . e($config['event'] ?? static::eventCode()) . '</strong>';
    }

    /**
     * @param array<string, mixed> $config
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateConfig(array $config): array
    {
        $event = $config['event'] ?? static::eventCode();

        return [
            'valid' => $event === static::eventCode(),
            'errors' => $event === static::eventCode() ? [] : ['Событие amoCRM не совпадает с выбранной карточкой.'],
        ];
    }

    abstract protected static function eventCode(): string;

    abstract protected static function eventName(): string;

    abstract protected static function eventDescription(): string;

    protected static function entity(): string
    {
        return static::parts()['entity'];
    }

    protected static function action(): string
    {
        return static::parts()['action'];
    }

    /**
     * @return array{entity: string, action: string}
     */
    protected static function parts(): array
    {
        $parts = explode('_', static::eventCode(), 2);

        return [
            'action' => $parts[0] ?? static::eventCode(),
            'entity' => $parts[1] ?? '',
        ];
    }

    protected static function eventIcon(): string
    {
        return match (static::entity()) {
            'lead' => 'heroicon-o-currency-dollar',
            'contact' => 'heroicon-o-user',
            'company' => 'heroicon-o-building-office',
            'customer' => 'heroicon-o-users',
            'task' => 'heroicon-o-check-circle',
            'talk' => 'heroicon-o-chat-bubble-left-right',
            'chat_template_review' => 'heroicon-o-chat-bubble-bottom-center-text',
            default => 'heroicon-o-bolt',
        };
    }

    protected static function eventColor(): string
    {
        return match (static::action()) {
            'add' => '#16A34A',
            'update' => '#2563EB',
            'delete' => '#DC2626',
            'restore' => '#059669',
            'responsible' => '#7C3AED',
            'status' => '#EA580C',
            'note' => '#CA8A04',
            default => '#0891B2',
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function eventCodeFromContext(mixed $subject, array $context): ?string
    {
        $candidates = [
            Arr::get($context, 'amocrm.event'),
            Arr::get($context, 'amo.event'),
            Arr::get($context, 'event'),
            Arr::get($context, 'event_code'),
            Arr::get($context, 'webhook.event'),
            Arr::get($context, 'webhook.event_code'),
            Arr::get($context, 'webhook.payload.event'),
            Arr::get($context, 'payload.event'),
        ];

        if (is_array($subject)) {
            $candidates[] = Arr::get($subject, 'event');
            $candidates[] = Arr::get($subject, 'event_code');
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function payloadFromContext(mixed $subject, array $context): array
    {
        $payload = Arr::get($context, 'webhook.payload')
            ?? Arr::get($context, 'payload')
            ?? Arr::get($context, 'amocrm.payload')
            ?? Arr::get($context, 'amo.payload');

        if (is_array($payload)) {
            return $payload;
        }

        return is_array($subject) ? $subject : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function payloadContainsEvent(array $payload): bool
    {
        $entityKey = static::payloadEntityKey();
        $actionKey = static::payloadActionKey();
        $items = Arr::get($payload, "{$entityKey}.{$actionKey}");

        if (!is_array($items) || $items === []) {
            return false;
        }

        if (in_array(static::eventCode(), ['add_contact', 'update_contact', 'delete_contact'], true)) {
            return $this->itemsContainType($items, 'contact');
        }

        if (in_array(static::eventCode(), ['add_company', 'update_company', 'delete_company'], true)) {
            return $this->itemsContainType($items, 'company');
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    protected function firstPayloadItem(array $payload): ?array
    {
        $items = Arr::get($payload, static::payloadEntityKey() . '.' . static::payloadActionKey());

        if (!is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (static::entity() === 'contact' && !$this->payloadItemMatchesType($item, 'contact')) {
                continue;
            }

            if (static::entity() === 'company' && !$this->payloadItemMatchesType($item, 'company')) {
                continue;
            }

            return $item;
        }

        return null;
    }

    protected static function payloadEntityKey(): string
    {
        return match (static::entity()) {
            'lead' => 'leads',
            'contact', 'company' => 'contacts',
            'customer' => 'customers',
            'task' => 'tasks',
            'talk' => 'talks',
            'chat_template_review' => 'chat_template_reviews',
            default => static::entity(),
        };
    }

    protected static function payloadActionKey(): string
    {
        return match (static::action()) {
            'responsible' => 'responsible',
            'restore' => 'restore',
            'status' => 'status',
            'note' => 'note',
            default => static::action(),
        };
    }

    /**
     * @param array<int|string, mixed> $items
     */
    protected function itemsContainType(array $items, string $type): bool
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($this->payloadItemMatchesType($item, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function payloadItemMatchesType(array $item, string $type): bool
    {
        $itemType = Arr::get($item, 'type');

        return $itemType === null || $itemType === $type;
    }
}

class AmoCrmResponsibleLeadTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'responsible_lead';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: сменился ответственный сделки';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда у сделки в amoCRM меняется ответственный.';
    }
}

class AmoCrmResponsibleContactTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'responsible_contact';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: сменился ответственный контакта';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда у контакта в amoCRM меняется ответственный.';
    }
}

class AmoCrmResponsibleCompanyTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'responsible_company';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: сменился ответственный компании';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда у компании в amoCRM меняется ответственный.';
    }
}

class AmoCrmResponsibleCustomerTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'responsible_customer';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: сменился ответственный покупателя';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда у покупателя в amoCRM меняется ответственный.';
    }
}

class AmoCrmResponsibleTaskTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'responsible_task';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: сменился ответственный задачи';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда у задачи в amoCRM меняется ответственный.';
    }
}

class AmoCrmRestoreLeadTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'restore_lead';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: сделка восстановлена';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда сделка восстановлена из удалённых.';
    }
}

class AmoCrmRestoreContactTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'restore_contact';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: контакт восстановлен';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда контакт восстановлен из удалённых.';
    }
}

class AmoCrmRestoreCompanyTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'restore_company';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: компания восстановлена';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда компания восстановлена из удалённых.';
    }
}

class AmoCrmAddLeadTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'add_lead';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: добавлена сделка';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда в amoCRM добавлена новая сделка.';
    }
}

class AmoCrmAddContactTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'add_contact';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: добавлен контакт';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда в amoCRM добавлен новый контакт.';
    }
}

class AmoCrmAddCompanyTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'add_company';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: добавлена компания';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда в amoCRM добавлена новая компания.';
    }
}

class AmoCrmAddCustomerTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'add_customer';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: добавлен покупатель';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда в amoCRM добавлен новый покупатель.';
    }
}

class AmoCrmAddTalkTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'add_talk';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: добавлена беседа';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда в amoCRM добавлена новая беседа.';
    }
}

class AmoCrmAddTaskTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'add_task';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: добавлена задача';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда в amoCRM добавлена новая задача.';
    }
}

class AmoCrmUpdateLeadTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'update_lead';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: сделка изменена';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда сделка в amoCRM изменена.';
    }
}

class AmoCrmUpdateContactTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'update_contact';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: контакт изменён';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда контакт в amoCRM изменён.';
    }
}

class AmoCrmUpdateCompanyTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'update_company';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: компания изменена';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда компания в amoCRM изменена.';
    }
}

class AmoCrmUpdateCustomerTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'update_customer';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: покупатель изменён';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда покупатель в amoCRM изменён.';
    }
}

class AmoCrmUpdateTalkTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'update_talk';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: беседа изменена';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда беседа в amoCRM изменена.';
    }
}

class AmoCrmUpdateTaskTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'update_task';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: задача изменена';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда задача в amoCRM изменена.';
    }
}

class AmoCrmDeleteLeadTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'delete_lead';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: сделка удалена';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда сделка удалена в amoCRM.';
    }
}

class AmoCrmDeleteContactTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'delete_contact';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: контакт удалён';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда контакт удалён в amoCRM.';
    }
}

class AmoCrmDeleteCompanyTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'delete_company';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: компания удалена';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда компания удалена в amoCRM.';
    }
}

class AmoCrmDeleteCustomerTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'delete_customer';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: покупатель удалён';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда покупатель удалён в amoCRM.';
    }
}

class AmoCrmDeleteTaskTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'delete_task';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: задача удалена';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда задача удалена в amoCRM.';
    }
}

class AmoCrmStatusLeadTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'status_lead';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: сменился статус сделки';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда у сделки в amoCRM меняется статус.';
    }
}

class AmoCrmNoteLeadTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'note_lead';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: примечание в сделке';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда в сделку amoCRM добавлено примечание.';
    }
}

class AmoCrmNoteContactTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'note_contact';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: примечание в контакте';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда в контакт amoCRM добавлено примечание.';
    }
}

class AmoCrmNoteCompanyTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'note_company';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: примечание в компании';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда в компанию amoCRM добавлено примечание.';
    }
}

class AmoCrmNoteCustomerTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'note_customer';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: примечание в покупателе';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда в покупателя amoCRM добавлено примечание.';
    }
}

class AmoCrmAddChatTemplateReviewTrigger extends AmoCrmWebhookTrigger
{
    protected static function eventCode(): string
    {
        return 'add_chat_template_review';
    }

    protected static function eventName(): string
    {
        return 'amoCRM: WhatsApp-шаблон на одобрении';
    }

    protected static function eventDescription(): string
    {
        return 'Запускается, когда WhatsApp-шаблон отправлен на одобрение.';
    }
}
