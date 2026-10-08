<div class="workflow-credentials-manager">
    <div class="workflow-credentials-toolbar">
        <label class="workflow-credentials-service">
            <span>Сервис</span>
            <select wire:model.live="workflowCredentialProvider" @disabled($workflowCredentialMode !== 'list')>
                @foreach ($providers as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        @if ($workflowCredentialMode === 'list')
            <button type="button" class="workflow-credentials-primary" wire:click="beginCreateWorkflowCredential">
                <x-filament::icon icon="heroicon-o-plus" />
                <span>Добавить подключение</span>
            </button>
        @endif
    </div>

    @if (in_array($workflowCredentialMode, ['create', 'edit'], true))
        <section class="workflow-credentials-form" aria-labelledby="workflow-credential-form-title">
            <div>
                <h3 id="workflow-credential-form-title">
                    {{ $workflowCredentialMode === 'edit' ? 'Изменить подключение' : 'Новое подключение' }}
                </h3>
                <p>
                    {{ $workflowCredentialMode === 'edit'
                        ? 'Введите новый токен или оставьте поле пустым, чтобы сохранить текущий.'
                        : 'Имя бота определится автоматически после проверки токена.' }}
                </p>
            </div>

            <label class="workflow-credentials-token">
                <span>Токен бота</span>
                <input
                    type="password"
                    wire:model="workflowCredentialToken"
                    autocomplete="new-password"
                    maxlength="300"
                    placeholder="123456789:AA…"
                    @if ($workflowCredentialMode === 'create') required @endif
                >
            </label>

            @error('workflowCredentialToken')
                <p class="workflow-credentials-error" role="alert">{{ $message }}</p>
            @enderror

            <div class="workflow-credentials-form-actions">
                <button type="button" class="workflow-credentials-primary" wire:click="saveWorkflowCredential" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveWorkflowCredential">Сохранить</span>
                    <span wire:loading wire:target="saveWorkflowCredential">Проверяем…</span>
                </button>
                <button type="button" class="workflow-credentials-secondary" wire:click="cancelWorkflowCredentialForm">Отмена</button>
            </div>
        </section>
    @elseif ($connections === [])
        <div class="workflow-credentials-empty">
            <span class="workflow-credentials-empty-icon"><x-filament::icon icon="heroicon-o-link" /></span>
            <div>
                <strong>Подключений пока нет</strong>
                <p>Добавьте бота, чтобы использовать его в Telegram-нодах.</p>
            </div>
        </div>
    @else
        <div class="workflow-credentials-list" aria-label="Сохранённые подключения">
            @foreach ($connections as $id => $name)
                <article class="workflow-credentials-row" wire:key="workflow-credential-{{ $id }}">
                    <span class="workflow-credentials-provider-icon" aria-hidden="true">
                        <x-filament::icon icon="heroicon-o-paper-airplane" />
                    </span>

                    <div class="workflow-credentials-identity">
                        <strong>{{ $name }}</strong>
                        <span>{{ $providers[$workflowCredentialProvider] ?? $workflowCredentialProvider }}</span>
                    </div>

                    @if ($workflowCredentialDeleteId === (int) $id)
                        <div class="workflow-credentials-confirm">
                            <span>Удалить?</span>
                            <button type="button" class="workflow-credentials-confirm-delete" wire:click="deleteWorkflowCredential({{ (int) $id }})">Да</button>
                            <button type="button" class="workflow-credentials-confirm-cancel" wire:click="cancelDeleteWorkflowCredential">Нет</button>
                        </div>
                    @else
                        <div class="workflow-credentials-row-actions">
                            <button type="button" wire:click="beginEditWorkflowCredential({{ (int) $id }})" title="Изменить {{ $name }}" aria-label="Изменить {{ $name }}">
                                <x-filament::icon icon="heroicon-o-pencil-square" />
                            </button>
                            <button type="button" class="is-danger" wire:click="requestDeleteWorkflowCredential({{ (int) $id }})" title="Удалить {{ $name }}" aria-label="Удалить {{ $name }}">
                                <x-filament::icon icon="heroicon-o-trash" />
                            </button>
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>

@once
    <style>
        .workflow-credentials-manager {
            --wcm-border: #e4e4e7;
            --wcm-surface: #fafafa;
            --wcm-surface-strong: #ffffff;
            --wcm-text: #18181b;
            --wcm-muted: #71717a;
            --wcm-brand: #f97352;
            --wcm-brand-hover: #ea6545;
            display: grid;
            gap: 1.25rem;
            color: var(--wcm-text);
        }

        .dark .workflow-credentials-manager {
            --wcm-border: #45464f;
            --wcm-surface: #292a30;
            --wcm-surface-strong: #32333a;
            --wcm-text: #f4f4f5;
            --wcm-muted: #a1a1aa;
        }

        .workflow-credentials-toolbar,
        .workflow-credentials-row,
        .workflow-credentials-form-actions,
        .workflow-credentials-row-actions,
        .workflow-credentials-confirm {
            display: flex;
            align-items: center;
        }

        .workflow-credentials-toolbar { justify-content: space-between; gap: 1rem; }

        .workflow-credentials-service {
            display: grid;
            gap: .4rem;
            min-width: 12rem;
            color: var(--wcm-muted);
            font-size: .8rem;
            font-weight: 600;
        }

        .workflow-credentials-service select,
        .workflow-credentials-token input {
            width: 100%;
            min-height: 2.75rem;
            border: 1px solid var(--wcm-border);
            border-radius: .65rem;
            background: var(--wcm-surface-strong);
            color: var(--wcm-text);
            font-size: .9rem;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }

        .workflow-credentials-service select { padding: 0 2.25rem 0 .8rem; }
        .workflow-credentials-token input { padding: 0 .8rem; }

        .workflow-credentials-service select:focus,
        .workflow-credentials-token input:focus {
            border-color: var(--wcm-brand);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--wcm-brand) 20%, transparent);
        }

        .workflow-credentials-primary,
        .workflow-credentials-secondary {
            min-height: 2.75rem;
            border-radius: .65rem;
            padding: 0 1rem;
            font-size: .88rem;
            font-weight: 650;
            transition: background .15s, border-color .15s, opacity .15s;
        }

        .workflow-credentials-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            background: var(--wcm-brand);
            color: white;
        }

        .workflow-credentials-primary:hover { background: var(--wcm-brand-hover); }
        .workflow-credentials-primary svg { width: 1.1rem; height: 1.1rem; }
        .workflow-credentials-primary:disabled { opacity: .6; cursor: wait; }

        .workflow-credentials-secondary {
            border: 1px solid var(--wcm-border);
            background: transparent;
            color: var(--wcm-text);
        }

        .workflow-credentials-list { display: grid; gap: .7rem; }

        .workflow-credentials-row {
            gap: .85rem;
            min-height: 4.5rem;
            padding: .85rem 1rem;
            border: 1px solid var(--wcm-border);
            border-radius: .85rem;
            background: var(--wcm-surface);
        }

        .workflow-credentials-provider-icon,
        .workflow-credentials-empty-icon {
            display: inline-grid;
            place-items: center;
            flex: 0 0 auto;
            width: 2.6rem;
            height: 2.6rem;
            border-radius: .75rem;
            background: rgba(34, 158, 217, .14);
            color: #229ed9;
        }

        .workflow-credentials-provider-icon svg,
        .workflow-credentials-empty-icon svg { width: 1.35rem; height: 1.35rem; }

        .workflow-credentials-identity { display: grid; gap: .15rem; min-width: 0; flex: 1; }

        .workflow-credentials-identity strong {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--wcm-text);
            font-size: .95rem;
        }

        .workflow-credentials-identity span { color: var(--wcm-muted); font-size: .78rem; }
        .workflow-credentials-row-actions { gap: .35rem; }

        .workflow-credentials-row-actions button {
            display: inline-grid;
            place-items: center;
            width: 2.35rem;
            height: 2.35rem;
            border: 1px solid transparent;
            border-radius: .6rem;
            color: var(--wcm-muted);
        }

        .workflow-credentials-row-actions button:hover {
            border-color: var(--wcm-border);
            background: var(--wcm-surface-strong);
            color: var(--wcm-text);
        }

        .workflow-credentials-row-actions button.is-danger:hover { color: #ef4444; }
        .workflow-credentials-row-actions svg { width: 1.15rem; height: 1.15rem; }

        .workflow-credentials-confirm { gap: .45rem; font-size: .82rem; }
        .workflow-credentials-confirm > span { color: var(--wcm-muted); }
        .workflow-credentials-confirm button { padding: .35rem .55rem; border-radius: .45rem; font-weight: 650; }
        .workflow-credentials-confirm-delete { background: #ef4444; color: white; }
        .workflow-credentials-confirm-cancel { color: var(--wcm-text); }

        .workflow-credentials-empty {
            display: flex;
            align-items: center;
            gap: .9rem;
            padding: 1rem;
            border: 1px dashed var(--wcm-border);
            border-radius: .85rem;
            background: var(--wcm-surface);
        }

        .workflow-credentials-empty strong,
        .workflow-credentials-form h3 { color: var(--wcm-text); font-size: .95rem; font-weight: 700; }
        .workflow-credentials-empty p,
        .workflow-credentials-form p { margin-top: .2rem; color: var(--wcm-muted); font-size: .82rem; }

        .workflow-credentials-form {
            display: grid;
            gap: 1rem;
            padding: 1rem;
            border: 1px solid var(--wcm-border);
            border-radius: .85rem;
            background: var(--wcm-surface);
        }

        .workflow-credentials-token { display: grid; gap: .4rem; color: var(--wcm-text); font-size: .82rem; font-weight: 650; }
        .workflow-credentials-error { color: #ef4444 !important; font-size: .8rem !important; }
        .workflow-credentials-form-actions { gap: .6rem; }

        @media (max-width: 640px) {
            .workflow-credentials-toolbar { align-items: stretch; flex-direction: column; }
            .workflow-credentials-service { width: 100%; }
            .workflow-credentials-primary { width: 100%; }
            .workflow-credentials-row { padding: .75rem; }
            .workflow-credentials-confirm > span { display: none; }
        }
    </style>
@endonce
