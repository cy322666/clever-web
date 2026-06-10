<div class="clever-order-page">
    <style>
        .clever-order-page {
            min-height: 100vh;
            background: #f4f6f8;
            color: #111827;
            padding: 32px 16px;
        }

        .clever-order-shell {
            width: 100%;
            max-width: 560px;
            margin: 0 auto;
        }

        .clever-order-alert {
            margin-bottom: 20px;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #991b1b;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 14px;
            line-height: 20px;
        }

        .clever-order-alert-success {
            border-color: #bbf7d0;
            background: #f0fdf4;
            color: #166534;
        }

        .clever-order-alert-link {
            display: inline-flex;
            margin-top: 10px;
            color: #14532d;
            font-weight: 700;
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .clever-order-company-search {
            margin-top: 16px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #ffffff;
            overflow: hidden;
        }

        .clever-order-company-search-note {
            margin-top: 12px;
            color: #4b5563;
            font-size: 13px;
            line-height: 18px;
        }

        .clever-order-company-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 14px 16px;
        }

        .clever-order-company-item + .clever-order-company-item {
            border-top: 1px solid #e5e7eb;
        }

        .clever-order-company-name {
            color: #111827;
            font-size: 14px;
            font-weight: 700;
            line-height: 20px;
        }

        .clever-order-company-meta {
            margin-top: 4px;
            color: #6b7280;
            font-size: 13px;
            line-height: 18px;
        }

        .clever-order-company-button {
            flex: 0 0 auto;
            min-height: 36px;
            border: 1px solid #111827;
            border-radius: 8px;
            background: #ffffff;
            padding: 8px 12px;
            color: #111827;
            font-size: 13px;
            font-weight: 700;
            line-height: 18px;
        }

        .clever-order-company-button:hover {
            background: #111827;
            color: #ffffff;
        }

        .clever-order-connection {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            background: #f0fdf4;
            padding: 12px 16px;
            color: #166534;
            font-size: 14px;
            line-height: 20px;
        }

        .clever-order-connection-error {
            border-color: #fecaca;
            background: #fef2f2;
            color: #991b1b;
        }

        .clever-order-connection-dot {
            width: 10px;
            height: 10px;
            flex: 0 0 auto;
            border-radius: 999px;
            background: currentColor;
        }

        .clever-order-title {
            margin: 0 0 20px;
            font-size: 24px;
            font-weight: 700;
            line-height: 32px;
            color: #111827;
        }

        .clever-order-page .fi-section {
            overflow: hidden;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
        }

        .clever-order-page .fi-section-content {
            padding: 20px;
        }

        .clever-order-page .fi-sc.fi-sc-has-gap {
            display: grid;
            gap: 16px;
        }

        .clever-order-page .fi-fo-field {
            display: grid;
            gap: 8px;
        }

        .clever-order-page .fi-fo-field-label {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #111827;
            font-size: 14px;
            font-weight: 600;
            line-height: 20px;
        }

        .clever-order-page .fi-fo-field-label-required-mark {
            color: #dc2626;
        }

        .clever-order-page .fi-input-wrp {
            min-height: 40px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #ffffff;
            color: #111827;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }

        .clever-order-page .fi-input-wrp:focus-within {
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.18);
        }

        .clever-order-page .fi-input,
        .clever-order-page .fi-select-input,
        .clever-order-page .fi-select-input-btn {
            width: 100%;
            min-height: 38px;
            padding: 8px 12px;
            color: #111827;
            font-size: 14px;
            line-height: 22px;
            outline: none !important;
            box-shadow: none !important;
        }

        .clever-order-page input,
        .clever-order-page select,
        .clever-order-page textarea,
        .clever-order-page button {
            font: inherit;
        }

        .clever-order-page input:focus,
        .clever-order-page select:focus,
        .clever-order-page textarea:focus,
        .clever-order-page button:focus {
            outline: none !important;
            box-shadow: none !important;
        }

        .clever-order-page input[type='number'] {
            appearance: textfield;
            -moz-appearance: textfield;
        }

        .clever-order-page input[type='number']::-webkit-inner-spin-button,
        .clever-order-page input[type='number']::-webkit-outer-spin-button {
            margin: 0;
            appearance: none;
            -webkit-appearance: none;
        }

        .clever-order-page .fi-select-input-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            text-align: left;
        }

        .clever-order-page .fi-input::placeholder,
        .clever-order-page .fi-select-input-placeholder {
            color: #9ca3af;
        }

        .clever-order-page .fi-disabled {
            background: #f9fafb;
            color: #6b7280;
        }

        .clever-order-page .fi-sc-text {
            color: #6b7280;
            font-size: 13px;
            line-height: 18px;
        }

        .clever-order-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }

        .clever-order-page .fi-btn {
            min-height: 42px;
            border-radius: 8px;
            background: #111827 !important;
            padding: 10px 18px;
            color: #ffffff !important;
            font-size: 14px;
            font-weight: 700;
            line-height: 20px;
            box-shadow: 0 10px 20px rgba(17, 24, 39, 0.12);
            transition: background 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
        }

        .clever-order-page .fi-btn:hover {
            background: #374151 !important;
            box-shadow: 0 12px 24px rgba(17, 24, 39, 0.16);
            transform: translateY(-1px);
        }

        @media (max-width: 640px) {
            .clever-order-page {
                padding: 24px 12px;
            }

            .clever-order-page .fi-section-content {
                padding: 18px;
            }

            .clever-order-title {
                font-size: 22px;
                line-height: 30px;
            }

            .clever-order-actions .fi-btn {
                width: 100%;
            }

            .clever-order-company-item {
                align-items: stretch;
                flex-direction: column;
            }

            .clever-order-company-button {
                width: 100%;
            }
        }
    </style>

    <form wire:init="loadProducts" wire:submit.prevent="create" class="clever-order-shell">
        @if ($formError)
            <div class="clever-order-alert">
                {{ $formError }}
            </div>
        @endif

        @if (session('success'))
            <div class="clever-order-alert clever-order-alert-success">
                {{ session('success') }}

                @if ($invoiceLink)
                    <br>
                    <a class="clever-order-alert-link" href="{{ $invoiceLink }}" target="_blank"
                       rel="noopener noreferrer">
                        Открыть счет
                    </a>
                @endif
            </div>
        @endif

        <h1 class="clever-order-title">
            Выставить счет
        </h1>

        @if ($amoConnectionChecked && $amoConnectionMessage)
            <div class="clever-order-connection @if (! $amoConnected) clever-order-connection-error @endif">
                <span class="clever-order-connection-dot"></span>
                <span>{{ $amoConnectionMessage }}</span>
            </div>
        @endif

        <x-filament::card>
            {{ $this->form }}
        </x-filament::card>

        @if ($companySearchError)
            <div class="clever-order-company-search-note">
                {{ $companySearchError }}
            </div>
        @elseif ($companySearchNotice)
            <div class="clever-order-company-search-note">
                {{ $companySearchNotice }}
            </div>
        @endif

        @if (! empty($companyMatches))
            <div class="clever-order-company-search">
                @foreach ($companyMatches as $company)
                    <div class="clever-order-company-item" wire:key="company-match-{{ $company['id'] }}">
                        <div>
                            <div class="clever-order-company-name">
                                {{ $company['name'] }}
                            </div>
                            <div class="clever-order-company-meta">
                                ИНН: {{ $company['inn'] ?: 'не указан' }}
                            </div>
                        </div>

                        <button
                            class="clever-order-company-button"
                            type="button"
                            wire:click="selectCompany({{ $company['id'] }})"
                        >
                            Подставить
                        </button>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="clever-order-actions">
            <x-filament::button type="submit">
                Выставить счет
            </x-filament::button>
        </div>
    </form>
</div>
