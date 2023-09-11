<?php

namespace App\Filament\Resources\Integrations\Bizon;

use App\Filament\Resources\Integrations\Bizon\WebinarResource\Pages;
use App\Jobs\Bizon\ViewerSend;
use App\Models\Integrations\Bizon\Webinar;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class WebinarResource extends Resource
{
    protected static ?string $model = Webinar::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (!Auth::user()->is_root) {

            $query->where('user_id', Auth::id());
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('roomid')
                    ->label('ID')
                    ->searchable()
                    ->sortable()
                    ->hidden(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Клиент')
                    ->searchable()
                    ->sortable()
                    ->hidden(fn() => !Auth::user()->is_root),

                Tables\Columns\TextColumn::make('room_title')
                    ->label('Название')
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('created')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('count')
                    ->label('Зрителей')
                    ->state(
                        fn(Webinar $webinar) => $webinar->viewers()->count()
                    ),

                Tables\Columns\TextColumn::make('success')
                    ->label('Отправлено')
                    ->state(
                        fn(Webinar $webinar) => $webinar->viewers()->where('status', 1)->count()
                    ),

//                Tables\Columns\TextColumn::make('fails')
//                    ->label('Ошибок')
//                    ->state(
//                        fn(Webinar $webinar) => $webinar->viewers()->where('status', 2)->count()
//                    ),

                //TODO relationship methods
                Tables\Columns\BooleanColumn::make('status')
                    ->label('Выгружен')
                    ->state(fn(Webinar $webinar) =>
                        $webinar
                            ->viewers()
                            ->where('status', 1)
                            ->count() ==
                        $webinar
                            ->viewers()
                            ->count()
                    )
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->actions([
//                Tables\Actions\Action::make('edit')
//                    ->url(fn (Webinar $record): string => route('posts.edit', $record))
//                    ->openUrlInNewTab()
            ])
            ->paginated([20, 30, 'all'])
            ->poll('15s')
            ->bulkActions([
                Tables\Actions\BulkAction::make('dispatched')
                    ->action(function (Collection $collection) {

                        $collection->each(function (Webinar $webinar) {

                            $user    = $webinar->user;
                            $setting = $user->bizon_settings;

                            $viewers = $webinar
                                ->viewers()
                                ->where('status', 0)
                                ->get();

                            $delay = 0;

                            foreach ($viewers as $viewer) {

                                ViewerSend::dispatch($viewer, $setting, $user->account)->delay(++$delay);
                            }
                        });
                    })
                    ->label('Догрузить')
            ])
            ->emptyStateActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebinars::route('/'),
        ];
    }
}
