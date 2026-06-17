<?php

namespace App\Livewire\Clever\Bayers;

use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class FormOrder extends Component implements HasForms
{
    use InteractsWithForms;

    private const AMO_FALLBACK_ACCOUNT_ID = 1;
    private const AMO_SUBDOMAIN = 'blacklever';
    private const AMO_SUPPLIER_ENTITY_ID = 3071967;

    public ?string $company_name = null;
    public ?string $inn = null;
    public ?int $product_id = null;
    public int|float|null $quantity = 1;
    public int|float|null $price = null;
    public int|float|null $total = null;
    public ?string $formError = null;
    public ?string $invoiceLink = null;
    public ?string $companySearchError = null;
    public ?string $companySearchNotice = null;
    public ?string $companyCreateError = null;
    public ?string $companyCreateNotice = null;
    public bool $amoConnectionChecked = false;
    public bool $amoConnected = false;
    public ?string $amoConnectionMessage = null;
    public bool $productsLoaded = false;

    public array $products = [];
    public array $productDetails = [];
    public array $companyMatches = [];

    public function mount(): void
    {
        $this->checkAmoConnection();

        $this->form->fill([
            'company_name' => null,
            'inn' => null,
            'product_id' => null,
            'quantity' => 1,
            'price' => null,
            'total' => null,
        ]);
    }

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                TextInput::make('company_name')
                    ->label('Компания-плательщик')
                    ->placeholder('ООО Компания')
                    ->maxLength(255)
                    ->live(debounce: 700)
                    ->required(),

                TextInput::make('inn')
                    ->label('ИНН')
                    ->placeholder('7700000000')
                    ->maxLength(32)
                    ->live(debounce: 700)
                    ->required(),

                Select::make('product_id')
                    ->label('Товар из amoCRM')
                    ->options($this->products)
                    ->placeholder('Выберите товар')
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $product = $this->productDetails[(int)$state] ?? null;

                        $set('price', $product['price'] ?? null);
                        $set('total', $product['price'] ?? null);
                    })
                    ->disabled(empty($this->products))
                    ->helperText(
                        empty($this->products) ? ($this->productsLoaded ? 'Список товаров amoCRM временно недоступен' : 'Список товаров загружается') : null
                    )
                    ->required(),

                TextInput::make('quantity')
                    ->label('Количество')
                    ->numeric()
                    ->minValue(0.01)
                    ->live(debounce: 500)
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $quantity = (float)$state;
                        $price = (float)($this->price ?? 0);

                        if ($quantity > 0 && $price > 0) {
                            $set('total', round($quantity * $price, 2));
                        }
                    })
                    ->default(1)
                    ->required(),

                TextInput::make('price')
                    ->label('Цена за единицу')
                    ->numeric()
                    ->minValue(0)
                    ->live(debounce: 500)
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $quantity = (float)($this->quantity ?? 1);
                        $price = (float)$state;

                        if ($quantity > 0 && $price > 0) {
                            $set('total', round($quantity * $price, 2));
                        }
                    })
                    ->required(),

                TextInput::make('total')
                    ->label('Общая стоимость')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ]);
    }

    public function loadProducts(): void
    {
        if ($this->productsLoaded || !$this->amoConnected) {
            return;
        }

        try {
            $amoApi = $this->amoClient();
            $productsCatalogId = $this->catalogIdByType($amoApi, 'products');
            $response = $amoApi->service->ajax()->get(
                "/api/v4/catalogs/{$productsCatalogId}/elements",
                ['limit' => 250]
            );

            $products = [];
            $productDetails = [];

            foreach (($response->_embedded->elements ?? []) as $element) {
                $id = (int)$element->id;
                $details = $this->extractProductDetails($element);

                $products[$id] = $details['label'];
                $productDetails[$id] = $details;
            }

            $this->products = $products;
            $this->productDetails = $productDetails;
            $this->productsLoaded = true;

        } catch (\Throwable $e) {
            logger()->warning('Список товаров Clever Bayers временно недоступен', [
                'subdomain' => self::AMO_SUBDOMAIN,
            ]);
            $this->products = [];
            $this->productDetails = [];
            $this->productsLoaded = true;
            $this->formError = 'Список товаров amoCRM временно недоступен. Попробуйте позже.';
        }
    }

    public function checkAmoConnection(): void
    {
        $this->amoConnectionChecked = true;
        $account = $this->amoAccount();
        $accountLabel = $account?->subdomain ? "{$account->subdomain}.amocrm.ru" : 'amoCRM';

        if (!$account) {
            $this->amoConnected = false;
            $this->amoConnectionMessage = 'Аккаунт amoCRM для формы не найден в БД.';

            return;
        }

        try {
            (new Client($account))->service->ajax()->get('/api/v4/account');

            $this->amoConnected = true;
            $this->amoConnectionMessage = "{$accountLabel} подключена.";
        } catch (\Throwable $e) {
            logger()->warning('Проверка подключения amoCRM Clever Bayers не прошла', [
                'account_id' => $account->id,
                'subdomain' => $account->subdomain,
            ]);

            if (str_contains($e->getMessage(), 'Token has been revoked')) {
                $account->active = false;
                $account->save();
            }

            $this->amoConnected = false;
            $this->amoConnectionMessage = str_contains($e->getMessage(), 'Token has been revoked')
                ? "{$accountLabel}: токен отозван. Переподключите интеграцию, чтобы искать компании, загрузить товары и выставлять счета."
                : "{$accountLabel}: подключение не работает. Переподключите интеграцию или проверьте доступ amoCRM.";
        }
    }

    public function create(): void
    {
        $this->formError = null;
        $this->invoiceLink = null;

        if (!$this->amoConnected) {
            $this->formError = 'amoCRM не подключена. Переподключите интеграцию и обновите форму.';

            return;
        }

        $data = $this->form->getState();

        try {
            $amoApi = $this->amoClient();
            $invoicesCatalogId = $this->catalogIdByType($amoApi, 'invoices');
            $product = $this->productDetails[(int)$data['product_id']] ?? null;
            $data = $this->normalizeInvoiceAmounts($data);

            if (!$product) {
                $this->formError = 'Товар не найден. Выберите товар заново.';

                return;
            }

            $response = $amoApi->service->ajax()->postJson(
                "/api/v4/catalogs/{$invoicesCatalogId}/elements",
                [$this->invoicePayload($data, $product)],
                ['with' => 'invoice_link']
            );

            $this->invoiceLink = $this->extractInvoiceLink($response);
            $invoiceElementId = $this->extractInvoiceElementId($response);

            if (!$this->invoiceLink && $invoiceElementId) {
                $invoice = $amoApi->service->ajax()->get(
                    "/api/v4/catalogs/{$invoicesCatalogId}/elements/{$invoiceElementId}",
                    ['with' => 'invoice_link']
                );

                $this->invoiceLink = $this->extractInvoiceLink($invoice);
            }

            if (!$this->invoiceLink) {
                $this->formError = 'Счет создан, но amoCRM не вернула ссылку. Проверьте счет в amoCRM.';

                return;
            }

            $this->sendInvoiceTelegramNotification($data, $product, $this->invoiceLink);

            session()->flash('success', 'Счет успешно создан в amoCRM.');
        } catch (\Throwable $e) {
            logger()->warning('Не удалось создать счет Clever Bayers', [
                'subdomain' => self::AMO_SUBDOMAIN,
            ]);
            $this->formError = 'Не удалось выставить счет. Попробуйте позже.';
        }
    }

    public function updatedCompanyName(): void
    {
        $this->searchCompanies();
    }

    public function updatedInn(): void
    {
        $this->searchCompanies();
    }

    public function searchCompanies(): void
    {
        if (!$this->amoConnected) {
            $this->companyMatches = [];
            $this->companySearchNotice = null;
            $this->companyCreateNotice = null;
            $this->companySearchError = 'amoCRM не подключена. Поиск компаний недоступен.';

            return;
        }

        $state = $this->formRawState();
        $companyName = trim((string)($this->company_name ?? $state['company_name'] ?? ''));
        $inn = preg_replace('/\D+/', '', (string)($this->inn ?? $state['inn'] ?? ''));
        $queries = [];

        if (mb_strlen($companyName) >= 3) {
            $queries[] = $companyName;
        }

        if (mb_strlen($inn) >= 4) {
            $queries[] = $inn;
        }

        if (empty($queries)) {
            $this->companyMatches = [];
            $this->companySearchError = null;
            $this->companySearchNotice = null;
            $this->companyCreateError = null;
            $this->companyCreateNotice = null;

            return;
        }

        try {
            $amoApi = $this->amoClient();
            $matches = [];

            foreach (array_unique($queries) as $query) {
                $response = $amoApi->service->ajax()->get('/api/v4/companies', [
                    'query' => $query,
                    'limit' => 5,
                ]);

                foreach (($response->_embedded->companies ?? []) as $company) {
                    $details = $this->extractCompanyDetails($company);
                    $matches[$details['id']] = $details;
                }
            }

            $this->companyMatches = array_slice(array_values($matches), 0, 5);
            $this->companySearchError = null;
            $this->companyCreateError = null;
            $this->companyCreateNotice = null;
            $this->companySearchNotice = empty($this->companyMatches) ? 'В amoCRM ничего не найдено.' : null;
        } catch (\Throwable $e) {
            logger()->warning('Поиск компании Clever Bayers в amoCRM временно недоступен', [
                'subdomain' => self::AMO_SUBDOMAIN,
            ]);
            $this->companyMatches = [];
            $this->companySearchNotice = null;
            $this->companyCreateNotice = null;
            $this->companySearchError = 'Поиск компаний amoCRM временно недоступен.';
        }
    }

    public function createCompany(): void
    {
        $this->companyCreateError = null;
        $this->companyCreateNotice = null;
        $this->companySearchError = null;
        $this->companySearchNotice = null;

        if (!$this->amoConnected) {
            $this->companyCreateError = 'amoCRM не подключена. Создание компании недоступно.';

            return;
        }

        $state = $this->formRawState();
        $companyName = trim((string)($this->company_name ?? $state['company_name'] ?? ''));
        $inn = preg_replace('/\D+/', '', (string)($this->inn ?? $state['inn'] ?? ''));

        if ($companyName === '') {
            $this->companyCreateError = 'Введите название компании.';

            return;
        }

        if ($inn === '') {
            $this->companyCreateError = 'Введите ИНН компании.';

            return;
        }

        try {
            $amoApi = $this->amoClient();
            $response = $amoApi->service->ajax()->postJson('/api/v4/companies', [
                $this->companyPayload($amoApi, $companyName, $inn),
            ]);

            $company = $response->_embedded->companies[0] ?? null;
            $companyId = isset($company->id) ? (int)$company->id : null;

            if (!$companyId) {
                $this->companyCreateError = 'amoCRM не вернула ID созданной компании.';

                return;
            }

            $createdCompany = [
                'id' => $companyId,
                'name' => $companyName,
                'inn' => $inn,
            ];

            $this->companyMatches = [$createdCompany];
            $this->selectCompany($companyId);
            $this->companySearchNotice = null;
            $this->companyCreateNotice = 'Компания создана в amoCRM и подставлена в форму.';
        } catch (\Throwable $e) {
            logger()->warning('Не удалось создать компанию Clever Bayers в amoCRM', [
                'subdomain' => self::AMO_SUBDOMAIN,
            ]);
            $this->companyCreateError = 'Не удалось создать компанию в amoCRM. Попробуйте позже.';
        }
    }

    public function selectCompany(int $companyId): void
    {
        $match = collect($this->companyMatches)->firstWhere('id', $companyId);

        if (!$match) {
            return;
        }

        $state = $this->formRawState();

        $this->form->fill(array_merge($state, [
            'company_name' => $match['name'] ?: ($state['company_name'] ?? null),
            'inn' => $match['inn'] ?: ($state['inn'] ?? null),
        ]));

        $this->company_name = $match['name'] ?: ($state['company_name'] ?? null);
        $this->inn = $match['inn'] ?: ($state['inn'] ?? null);

        $this->companyMatches = [];
        $this->companySearchError = null;
        $this->companySearchNotice = 'Реквизиты подставлены из amoCRM.';
    }

    private function catalogIdByType(Client $amoApi, string $type): int
    {
        $response = $amoApi->service->ajax()->get('/api/v4/catalogs', ['limit' => 250]);

        foreach (($response->_embedded->catalogs ?? []) as $catalog) {
            if (($catalog->type ?? null) === $type) {
                return (int)$catalog->id;
            }
        }

        throw new \RuntimeException("amoCRM catalog {$type} is not configured");
    }

    private function extractProductDetails(object $element): array
    {
        $details = [
            'label' => (string)$element->name,
            'name' => (string)$element->name,
            'sku' => null,
            'description' => (string)$element->name,
            'price' => null,
            'unit_type' => 'шт.',
        ];

        foreach (($element->custom_fields_values ?? []) as $field) {
            $code = $field->field_code ?? null;
            $value = $field->values[0]->value ?? null;

            if ($value === null) {
                continue;
            }

            if ($code === 'SKU') {
                $details['sku'] = (string)$value;
            }

            if ($code === 'DESCRIPTION') {
                $details['description'] = (string)$value;
            }

            if ($code === 'PRICE') {
                $details['price'] = (float)$value;
            }
        }

        if ($details['price'] !== null) {
            $details['label'] .= ' - ' . number_format($details['price'], 2, '.', ' ') . ' ₽';
        }

        return $details;
    }

    private function extractCompanyDetails(object $company): array
    {
        $details = [
            'id' => (int)$company->id,
            'name' => (string)$company->name,
            'inn' => null,
        ];

        foreach (($company->custom_fields_values ?? []) as $field) {
            foreach (($field->values ?? []) as $value) {
                $rawValue = $value->value ?? null;

                if (is_object($rawValue)) {
                    $details['inn'] ??= $this->stringFromObject($rawValue, ['vat_id', 'inn']);
                    continue;
                }

                if (!is_scalar($rawValue)) {
                    continue;
                }

                $fieldCode = mb_strtoupper((string)($field->field_code ?? ''));
                $fieldName = mb_strtoupper((string)($field->field_name ?? ''));
                $fieldText = $fieldCode . ' ' . $fieldName;

                if ($details['inn'] === null && str_contains($fieldText, 'ИНН')) {
                    $details['inn'] = (string)$rawValue;
                }
            }
        }

        return $details;
    }

    private function stringFromObject(object $value, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($value->{$key}) && is_scalar($value->{$key}) && $value->{$key} !== '') {
                return (string)$value->{$key};
            }
        }

        return null;
    }

    private function formRawState(): array
    {
        $state = $this->form->getRawState();

        return is_array($state) ? $state : $state->toArray();
    }

    private function invoicePayload(array $data, array $product): array
    {
        $payer = array_filter([
            'name' => $data['company_name'],
            'vat_id' => $data['inn'],
            'type' => 'legal',
        ], fn($value): bool => $value !== null && $value !== '');

        $item = array_filter([
            'sku' => $product['sku'] ?? null,
            'product_id' => (int)$data['product_id'],
            'description' => $product['name'] ?: $product['description'],
            'unit_price' => (float)$data['price'],
            'quantity' => (float)$data['quantity'],
            'unit_type' => $product['unit_type'] ?? 'шт.',
        ], fn($value): bool => $value !== null && $value !== '');

        return [
            'name' => 'Счет для ' . $data['company_name'],
            'custom_fields_values' => [
                [
                    'field_code' => 'PAYER',
                    'values' => [
                        ['value' => $payer],
                    ],
                ],
                [
                    'field_code' => 'BILL_STATUS',
                    'values' => [
                        ['enum_code' => 'created'],
                    ],
                ],
                [
                    'field_code' => 'SUPPLIER',
                    'values' => [
                        [
                            'value' => [
                                'entity_id' => self::AMO_SUPPLIER_ENTITY_ID,
                            ],
                        ],
                    ],
                ],
                [
                    'field_code' => 'ITEMS',
                    'values' => [
                        ['value' => $item],
                    ],
                ],
            ],
        ];
    }

    private function companyPayload(Client $amoApi, string $companyName, string $inn): array
    {
        $payload = [
            'name' => $companyName,
        ];

        $innField = $this->companyInnCustomField($amoApi, $inn);

        if ($innField !== null) {
            $payload['custom_fields_values'] = [$innField];
        }

        return $payload;
    }

    private function companyInnCustomField(Client $amoApi, string $inn): ?array
    {
        $fieldId = $this->companyInnFieldId($amoApi);

        if ($fieldId === null) {
            return null;
        }

        return [
            'field_id' => $fieldId,
            'values' => [
                ['value' => $inn],
            ],
        ];
    }

    private function companyInnFieldId(Client $amoApi): ?int
    {
        $response = $amoApi->service->ajax()->get('/api/v4/companies/custom_fields', ['limit' => 250]);

        foreach (($response->_embedded->custom_fields ?? []) as $field) {
            $code = mb_strtoupper((string)($field->field_code ?? $field->code ?? ''));
            $name = mb_strtoupper((string)($field->name ?? ''));

            if ($code === 'INN' || $name === 'ИНН' || str_contains($name, 'ИНН')) {
                return (int)$field->id;
            }
        }

        return null;
    }

    private function normalizeInvoiceAmounts(array $data): array
    {
        $quantity = max((float)($data['quantity'] ?? 1), 0.01);
        $total = (float)($data['total'] ?? 0);

        if ($total > 0) {
            $data['price'] = round($total / $quantity, 2);
        } else {
            $data['total'] = round($quantity * (float)($data['price'] ?? 0), 2);
        }

        return $data;
    }

    private function sendInvoiceTelegramNotification(array $data, array $product, string $invoiceLink): void
    {
        $token = (string)config('services.clever_bayers_invoice_telegram.token');
        $chatId = (string)config('services.clever_bayers_invoice_telegram.chat_id');

        if ($token === '' || $chatId === '') {
            logger()->warning('Telegram уведомление о счете Clever Bayers не настроено');

            return;
        }

        $quantity = (float)$data['quantity'];
        $price = (float)$data['price'];
        $total = (float)($data['total'] ?? ($quantity * $price));

        $message = implode("\n", [
            '<b>Выставлен счет</b>',
            '',
            'Клиент: ' . e($data['company_name']),
            'Товар: ' . e($product['name'] ?? $product['label'] ?? 'не указан'),
            'Количество: ' . rtrim(rtrim(number_format($quantity, 2, '.', ' '), '0'), '.'),
            'Сумма: ' . number_format($total, 2, '.', ' ') . ' ₽',
            '',
            'Счет: ' . e($invoiceLink),
        ]);

        try {
            $response = Http::timeout(5)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => false,
            ]);

            if ($response->failed()) {
                logger()->warning('Telegram уведомление о счете Clever Bayers не отправлено', [
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            logger()->warning('Telegram уведомление о счете Clever Bayers временно недоступно', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function extractInvoiceLink(mixed $response): ?string
    {
        if (!is_object($response)) {
            return null;
        }

        $element = $response->_embedded->elements[0] ?? $response;

        return $element->invoice_link ?? null;
    }

    private function extractInvoiceElementId(mixed $response): ?int
    {
        if (!is_object($response)) {
            return null;
        }

        $element = $response->_embedded->elements[0] ?? $response;

        return isset($element->id) ? (int)$element->id : null;
    }

    /**
     * @throws \Exception
     */
    private function amoClient(): Client
    {
        $account = $this->amoAccount();

        if (!$account) {
            throw new \RuntimeException('amoCRM account is not configured');
        }

        return new Client($account);
    }

    private function amoAccount(): ?Account
    {
        return Account::query()
            ->where('subdomain', self::AMO_SUBDOMAIN)
            ->where('active', true)
            ->whereNotNull('access_token')
            ->whereNotNull('refresh_token')
            ->orderByRaw("case when widget = ? then 0 else 1 end", [Account::DEFAULT_WIDGET])
            ->orderByDesc('id')
            ->first()
            ?? Account::query()->find(self::AMO_FALLBACK_ACCOUNT_ID);
    }

    public function render()
    {
        return view('livewire.clever.bayers.form-order')
            ->layout('components.layouts.app');
    }
}
