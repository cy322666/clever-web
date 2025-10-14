<?php

namespace App\Livewire\Clever\Bayers;

use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Livewire\Component;

class FormOrder extends Component implements HasForms
{
    use InteractsWithForms;

    public array $companies = [];
    public array $products  = [];

    public function mount(): void
    {
        // 🧩 Инициализируем API
        $amoApi = new Client(Account::query()->find(3));

        // --- Загружаем продукты из кастомного поля ---
        $this->products = []; // важно очистить перед заполнением
        $fields = $amoApi->service->ajax()->get('/api/v4/customers/custom_fields');

        foreach ($fields->_embedded->custom_fields as $field) {
            if ($field->id == 436721) {
                foreach ($field->enums as $enum) {
                    $this->products[] = [
                        'id'   => $enum->id,
                        'name' => $enum->value,
                    ];
                }
            }
        }

        // --- Загружаем компании ---
        $this->companies = []; // очистка массива
        $companiesCollection = $amoApi->service->companies;

        foreach ($companiesCollection->toArray() as $companyArray) {
            $this->companies[] = [
                'id'   => $companyArray['id'],
                'name' => $companyArray['name'],
            ];
        }

        // --- Заполняем форму начальными значениями ---
        $this->form->fill([
            'company_id' => null,
            'product_id' => null,
        ]);
    }

    public function getCompanyOptions(): array
    {
        return collect($this->companies)->pluck('name', 'id')->toArray();
    }

    public function getProductOptions(): array
    {
        return collect($this->products)->pluck('name', 'id')->toArray();
    }

    protected function getCompanyName($id): ?string
    {
        return collect($this->companies)->firstWhere('id', $id)['name'] ?? null;
    }

    protected function getProductName($id): ?string
    {
        return collect($this->products)->firstWhere('id', $id)['name'] ?? null;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('company_id')
                    ->label('Клиент')
                    ->options(fn () => $this->getCompanyOptions())
                    ->searchable()
                    ->getOptionLabelUsing(fn ($value) => $this->getCompanyName($value))
                    ->placeholder('Выберите компанию')
                    ->required(),

                Select::make('product_id')
                    ->label('Услуга или продукт')
                    ->options(fn () => $this->getProductOptions())
                    ->searchable()
                    ->getOptionLabelUsing(fn ($value) => $this->getProductName($value))
                    ->placeholder('Выберите услугу / продукт')
                    ->required(),

                Checkbox::make('is_advance')
                    ->label('Нужен аванс')
                    ->default(false),

                DatePicker::make('date')
                    ->label('Дата платежа')
                    ->required(),
            ]);
    }

    public function create(): void
    {
        $data = $this->form->getState();

        dump($data);

        // Пример: ContactRequest::create($data);

        $this->form->fill();

        session()->flash('success', 'Сообщение отправлено!');
    }

    public function render()
    {
        return view('livewire.clever.bayers.form-order');
    }
}
