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

    public ?array $formData = [
        'company_id' => null,
        'product_id' => null,
        'is_advance' => false,
        'date' => null,
    ];

    public function mount(): void
    {
        $this->loadAmoData();

        // Заполняем форму начальными данными (после загрузки опций)
        $this->form->fill($this->formData);
    }

    private function loadAmoData(): void
    {
        $amoApi = new Client(Account::query()->find(3));

        // --- Загружаем продукты ---
        $this->products = [];
        $fields = $amoApi->service->ajax()->get('/api/v4/customers/custom_fields');

        foreach ($fields->_embedded->custom_fields as $field) {
            if ($field->id == 436721) {
                foreach ($field->enums as $enum) {
                    $this->products[$enum->id] = $enum->value;
                }
            }
        }

        // --- Загружаем компании ---
        $this->companies = [];
        $companiesCollection = $amoApi->service->companies;

        foreach ($companiesCollection->toArray() as $companyArray) {
            $this->companies[$companyArray['id']] = $companyArray['name'];
        }
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('company_id')
                    ->label('Клиент')
                    ->options(fn () => $this->companies)
                    ->searchable()
                    ->placeholder('Выберите компанию')
                    ->required(),

                Select::make('product_id')
                    ->label('Услуга или продукт')
                    ->options(fn () => $this->products)
                    ->searchable()
                    ->placeholder('Выберите услугу / продукт')
                    ->required(),

                Checkbox::make('is_advance')
                    ->label('Нужен аванс'),

                DatePicker::make('date')
                    ->label('Дата платежа')
                    ->required(),
            ])
            ->statePath('formData');
    }

    public function create(): void
    {
        $data = $this->form->getState();
        dump($data);

        $this->form->fill();
        session()->flash('success', 'Данные отправлены!');
    }

    public function render()
    {
        return view('livewire.clever.bayers.form-order');
    }
}
