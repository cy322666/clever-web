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

    public function mount(): void
    {
        // Начальные значения формы
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
                // --- Клиент (ленивый поиск из amoCRM) ---
                Select::make('company_id')
                    ->label('Клиент')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => $this->searchCompanies($search))
                    ->getOptionLabelUsing(fn ($value) => $this->getCompanyName($value))
                    ->placeholder('Начните вводить название компании')
                    ->required(),

                // --- Продукт или услуга (ленивый поиск из amoCRM) ---
                Select::make('product_id')
                    ->label('Услуга или продукт')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => $this->searchProducts($search))
                    ->getOptionLabelUsing(fn ($value) => $this->getProductName($value))
                    ->placeholder('Начните вводить услугу / продукт')
                    ->required(),

                Checkbox::make('is_advance')
                    ->label('Нужен аванс')
                    ->default(false),

                DatePicker::make('date')
                    ->label('Дата платежа')
                    ->required(),
            ]);
    }

    // =============================
    // 🧩 Поиск компаний в amoCRM
    // =============================
    protected function searchCompanies(string $search): array
    {
        $amoApi = new Client(Account::query()->find(3));

        try {
            // amoCRM SDK обычно поддерживает метод search
            $companiesCollection = $amoApi->service->companies;

            foreach ($companiesCollection->toArray() as $companyArray) {

                $this->companies[] = [
                    'id'   => $companyArray['id'],
                    'name' => $companyArray['name'], //проект??
                ];
            }

            return collect($this->companies)
                ->pluck('name', 'id')
                ->toArray();

        } catch (\Throwable $e) {
            logger()->error('Ошибка при поиске компаний: ' . $e->getMessage());
            return [];
        }
    }

    protected function getCompanyName($id): ?string
    {
        if (!$id) {
            return null;
        }

        $amoApi = new Client(Account::query()->find(3));

        try {
            $company = $amoApi->service->companies()->find($id);

            return $company?->name ?? null;
        } catch (\Throwable $e) {
            logger()->error('Ошибка при получении компании: ' . $e->getMessage());
            return null;
        }
    }

    // =============================
    // 🧩 Поиск продуктов в amoCRM
    // =============================
    protected function searchProducts(string $search): array
    {
        $amoApi = new Client(Account::query()->find(3));

        $fields = $amoApi->service->ajax()->get('/api/v4/customers/custom_fields');

        foreach ($fields->_embedded->custom_fields as $key => $field) {

            if ($field->id == 436721) {
                foreach ($field->enums as $enum) {
                    $this->products[] = [
                        'id' => $enum->id,
                        'name' => $enum->value,
                    ];
                }
            }
        }

        return $this->products;
    }

    protected function getProductName($id): ?string
    {
        if (!$id) {
            return null;
        }

//        $amoApi = new Client(Account::query()->find(3));
//
//        try {
//            $fields = $amoApi->service->ajax()->get('/api/v4/customers/custom_fields');
//            $productField = collect($fields->_embedded->custom_fields)
//                ->firstWhere('id', 436721);
//
//            if (!$productField) {
//                return null;
//            }

            return collect($this->products)
                ->firstWhere('id', $id)?->value ?? null;

//        } catch (\Throwable $e) {
//            logger()->error('Ошибка при получении продукта: ' . $e->getMessage());
//            return null;
//        }
    }

    // =============================
    // 📦 Обработка формы
    // =============================
    public function create(): void
    {
        $data = $this->form->getState();

        dump($data); // можно заменить на сохранение

        $this->form->fill();

        session()->flash('success', 'Заявка успешно отправлена!');
    }

    public function render()
    {
        return view('livewire.clever.bayers.form-order');
    }
}
