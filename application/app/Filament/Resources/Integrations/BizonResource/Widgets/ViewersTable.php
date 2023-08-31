<?php

namespace App\Filament\Resources\Integrations\BizonResource\Widgets;

use App\Models\Integrations\Bizon\Webinar;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

class ViewersTable extends TableWidget
{
    protected static string $view = 'filament.resources.integrations.bizon-resource.widgets.viewers-table';

//    public static function table(Table $table): Table
//    {
//        return $table
//            ->columns([
//                TextColumn::make('roomid')
//                    ->label('ID')
//                    ->searchable()
//                    ->sortable(),
//
//                TextColumn::make('room_title')
//                    ->label('Название')
//                    ->searchable(),
//
//                TextColumn::make('created')
//                    ->label('Создан')
//                    ->dateTime()
//                    ->sortable(),
//            ])
//            ->filters([])
//            ->actions([
////                Tables\Actions\Action::make('edit')
//////                    ->url(fn (Webinar $record): string => route('posts.edit', $record))
////                    ->openUrlInNewTab()
//            ])
//            ->bulkActions([
////                Tables\Actions\BulkActionGroup::make([]),
//            ])
//            ->emptyStateActions([]);
//    }
//
//    protected function getTableColumns(): array
//    {
//        return [
//            TextColumn::make('roomid')
//                ->label('ID')
//                ->searchable()
//                ->sortable(),
//
//            TextColumn::make('room_title')
//                ->label('Название')
//                ->searchable(),
//
//            TextColumn::make('created')
//                ->label('Создан')
//                ->dateTime()
//                ->sortable(),
//        ];
//    }
//
//    protected function getTableQuery(): Builder
//    {
//        return Webinar::query();
//    }
}
