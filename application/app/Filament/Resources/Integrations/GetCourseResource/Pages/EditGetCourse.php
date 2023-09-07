<?php

namespace App\Filament\Resources\Integrations\GetCourseResource\Pages;

use App\Filament\Resources\Integrations\GetCourseResource;
use App\Helpers\Actions\UpdateButton;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

use function route;

class EditGetCourse extends EditRecord
{
    protected static string $resource = GetCourseResource::class;

    protected function getActions(): array
    {
        return [
            UpdateButton::getAction($this->record),

            Actions\Action::make('instruction')
                ->label('Инструкция'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['link_order'] = route('getcourse.order', [
            'user' => Auth::user()->uuid,
        ]);

        $data['link_form'] = route('getcourse.form', [
            'user' => Auth::user()->uuid,
        ]);

        return $data;
    }
}
