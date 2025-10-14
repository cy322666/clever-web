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
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('companies')
                    ->label('Имя')
                    ->searchable()
                    ->options([
                        'id' => 'ID',
                        'name' => 'Name'
                    ])
                    ->required(),
                Select::make('products')
                    ->label('Услуга или продукт')
                    ->searchable()
                    ->options([
                        'id' => 'ID',
                        'name' => 'Name'
                    ])
                    ->required(),
                Checkbox::make('is_advance')
                    ->label('Нужен аванс')
                    ->required(),
                DatePicker::make('date')
                    ->label('Дата платежа')
                    ->required(),
            ]);
    }

    public function create(): void
    {
        $data = $this->form->getState();

        dump($data);
        // Обработка данных формы, например, сохранение в базу данных
        // или отправка email.
        // Пример: ContactRequest::create($data);

        // Сброс формы
        $this->form->fill();

        // Отправка flash-сообщения
        session()->flash('success', 'Сообщение отправлено!');
    }

    public function render()
    {
        $amoApi = (new Client(Account::query()->find(3)));

        $fields = $amoApi->service->ajax()->get('/api/v4/customers/custom_fields');

        foreach ($fields->_embedded->custom_fields as $key => $field) {

            if ($field->id == 436721) {

                foreach ($field->enums as $enum) {

                    $this->products[] = [
                        'id'   => $enum->id,
                        'name' => $enum->value,
                    ];
                }
            }
        }

        $companiesCollection = $amoApi->service->companies;

        foreach ($companiesCollection->toArray() as $companyArray) {

            $this->companies[] = [
                'id' => $companyArray['id'],
                'name' => $companyArray['name'],
                //проект??
            ];
        }

//        $this->products = [
//            [
//                'id' => 1,
//                'name' => 2,
//            ],
//            [
//                'id' => 1,
//                'name' => 2,
//            ],
//        ];

        return view('livewire.clever.bayers.form-order');
    }
}
