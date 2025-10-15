<?php

namespace App\Livewire\Clever\Bayers;

use App\Models\Clever\Company;
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
                Select::make('company_id')
                    ->label('Клиент')
                    ->searchable()
                    ->model(Company::class)
                    ->searchable()
//                    ->getSearchResultsUsing(fn (string $search) =>
//                        Company::query()
//                            ->where('name', 'like', "%{$search}%")
//                            ->limit(20)
//                            ->pluck('name', 'id')
//                            ->toArray()
//                        )
//                    ->getOptionLabelUsing(fn ($value) => Company::find($value)?->name)
                    ->placeholder('Начните вводить название компании')
                    ->required(),

                Select::make('product_id')
                    ->label('Услуга или продукт')
                    ->options($this->products)
                    ->placeholder('Выберите продукт')
                    ->required(),

                Checkbox::make('is_advance')
                    ->label('Нужен аванс')
                    ->default(false),

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
                        $products[$enum->id] = $enum->value;
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

        dump($data); // можно заменить на сохранение

        session()->flash('success', 'Заявка успешно отправлена!');
    }

    public function render()
    {
        return view('livewire.clever.bayers.form-order');
    }
}
