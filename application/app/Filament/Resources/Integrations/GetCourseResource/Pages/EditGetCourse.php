<?php

namespace App\Filament\Resources\Integrations\GetCourseResource\Pages;

use App\Filament\Resources\Integrations\GetCourseResource;
use App\Filament\Resources\Integrations\Tilda\FormResource;
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
                ->label('Инструкция')
                ->url('')//TODO
                ->openUrlInNewTab(),

            Actions\Action::make('list')
                ->label('История')
                ->url('')//TODO
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['link_order'] = route('getcourse.order', [
                'user' => Auth::user()->uuid
            ]) . '?phone={object.user.phone}&name={object.user.first_name}&email={object.user.email}&number={object.number}&id={object.id}&positions={object.positions}&left_cost_money={object.left_cost_money}&cost_money={object.cost_money}&payed_money={object.payed_money}&status={object.status}&link={object.payment_link}&promocode={object.promocode}';

        if ($data['settings']) {
            $data['settings'] = json_decode($data['settings'], true);

            for ($i = 0; count($data['settings']) !== $i; $i++) {
                $data['settings'][$i]['link_form'] = \route('getcourse.form', [
                        'user' => Auth::user()->uuid,
                        'form' => $i,
                    ]) . '/?phone={object.phone}&name={object.first_name}&email={object.email}&utm_source={object.create_session.utm_source}&utm_medium={create_session.utm_medium}&utm_campaign={create_session.utm_campaign}&utm_content={create_session.utm_content}&utm_term={create_session.utm_term}';

                $body = !empty($data['bodies']) ? json_decode($data['bodies'], true)[$i] : [];

                $data['settings'][$i]['body'] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
        }

        return $data;
    }
}
