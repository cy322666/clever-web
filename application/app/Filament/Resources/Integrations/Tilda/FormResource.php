<?php

namespace App\Filament\Resources\Integrations\Tilda;

use App\Filament\Resources\Integrations\Tilda\FormResource\Pages;
use App\Jobs\Tilda\FormSend;
use App\Models\Integrations\Bizon\Webinar;
use App\Models\Integrations\Tilda\Form;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

class FormResource extends Resource
{
    protected static ?string $model = Form::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Клиент')
                    ->searchable()
                    ->sortable()
                    ->hidden(fn() => !Auth::user()->is_root),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('lead_id')
                    ->label('Сделка'),

                Tables\Columns\TextColumn::make('contact_id')
                    ->label('Контакт'),

                Tables\Columns\BooleanColumn::make('status')
                    ->label('Выгружен'),

                 Tables\Columns\TextColumn::make('site')
                     ->label('Форма'),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 20, 'all'])
            ->poll('15s')
            ->filters([
                //
            ])
            ->actions([])
            ->bulkActions([
                Tables\Actions\BulkAction::make('dispatched')
                    ->action(function (Collection $collection) {

                        $collection->each(function (Form $form) {

                            $user    = $form->user;
                            $setting = $user->tilda_settings;

                            FormSend::dispatch($form, $user->account, $setting);
                        });
                    })
                    ->label('Догрузить')
            ])
            ->emptyStateActions([]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListForms::route('/'),
        ];
    }
}
