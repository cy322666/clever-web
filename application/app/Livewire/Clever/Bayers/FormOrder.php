<?php

namespace App\Livewire\Clever\Bayers;

use App\Models\Clever\Company;
use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use Carbon\Carbon;
use Dflydev\DotAccessData\Data;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Livewire\Component;

class FormOrder extends Component implements HasForms
{
    use InteractsWithForms;

    public ?int $company_id = null;
    public ?int $sale = null;
    public ?string $product_id = null;
    public bool $is_advance = false;
    public ?string $date = null;

    public array $products = [];

    public function mount(): void
    {
        $this->loadProducts();

        $this->form->fill([
            'company_id' => null,
            'product_id' => null,
            'is_advance' => false,
            'date' => now(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // 🏢 Поиск компании по названию
                Select::make('company_id')
                    ->label('Клиент')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) =>
                    Company::query()
                        ->where('name', 'ilike', "%{$search}%")
                        ->limit(20)
                        ->pluck('name', 'id')
                        ->toArray()
                    )
                    ->getOptionLabelUsing(fn ($value): ?string =>
                    Company::find($value)?->name
                    )
                    ->placeholder('Введите название компании')
                    ->required(),

                // 📦 Список продуктов
                Select::make('product_id')
                    ->label('Услуга или продукт')
                    ->options($this->products)
                    ->placeholder('Выберите продукт')
                    ->required(),

                Checkbox::make('is_advance')
                    ->label('Нужен аванс')
                    ->default(false),

                TextInput::make('sale')
                    ->label('Сумма платежа (если известно)')
                    ->required(),

                DatePicker::make('date')
                    ->label('Дата платежа')
                    ->required(),
            ]);
    }

    protected function loadProducts(): void
    {
        $amoApi = new Client(Account::query()->find(3));

        try {
            $fields = $amoApi->service->ajax()->get('/api/v4/customers/custom_fields');

            $products = [];

            foreach ($fields->_embedded->custom_fields as $field) {
                if ($field->id == 436721) {
                    foreach ($field->enums as $enum) {
                        $products[$enum->value] = $enum->value;
                    }
                }
            }

            $this->products = $products;

        } catch (\Throwable $e) {
            logger()->error('Ошибка при загрузке продуктов: ' . $e->getMessage());
            $this->products = [];
        }
    }

    public function create(): void
    {
        $data = $this->form->getState();
/*
 * array:5 [▼ // app/Livewire/Clever/Bayers/FormOrder.php:114
  "company_id" => 31
  "product_id" => 3051189
  "is_advance" => true
  "sale" => 11111
  "date" => "2025-10-15"
]
 */
        //TODO avans
        $amoApi = (new Client(Account::query()->find(3)));

        $companyModel = Company::query()->find($data['company_id']);

        $company = $amoApi->service->companies()->find($companyModel->company_id);

        $customer = $company->createCustomer();
        $customer->name = $companyModel->name.' '.$data['product_id'];
        $customer->next_date = Carbon::parse($data['date'])->timestamp;
        $customer->next_price = $data['sale'];
        $customer->cf('Услуга / продукт')->setValue($data['product_id']);
        $customer->save();

        session()->flash('success', 'Заявка успешно отправлена!');
    }

    public function render()
    {
        return view('livewire.clever.bayers.form-order');
    }
}
