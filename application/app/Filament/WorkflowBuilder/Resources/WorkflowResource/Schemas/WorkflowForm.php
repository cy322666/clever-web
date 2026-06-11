<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Schemas;

use App\Workflows\FailureStrategies;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkflowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)
                ->columnSpanFull()
                ->extraAttributes(['class' => 'match-height'])
                ->schema([
                    Section::make(__('filament-workflows::workflows.sections.workflow_details.title'))
                        ->icon('heroicon-o-document-text')
                        ->compact()
                        ->columnSpan(2)
                        ->schema([
                            TextInput::make('name')
                                ->label(__('filament-workflows::workflows.fields.name.label'))
                                ->required()
                                ->maxLength(255)
                                ->placeholder(__('filament-workflows::workflows.fields.name.placeholder')),

                            Textarea::make('description')
                                ->label(__('filament-workflows::workflows.fields.description.label'))
                                ->rows(2)
                                ->placeholder(__('filament-workflows::workflows.fields.description.placeholder')),
                        ]),

                    Section::make(__('filament-workflows::workflows.sections.settings.title'))
                        ->icon('heroicon-o-cog-6-tooth')
                        ->compact()
                        ->columnSpan(1)
                        ->schema([
                            Toggle::make('is_active')
                                ->label(__('filament-workflows::workflows.fields.is_active.label'))
                                ->default(true),

                            Select::make('failure_strategy')
                                ->label(__('filament-workflows::workflows.fields.failure_strategy.label'))
                                ->options([
                                    FailureStrategies::STOP => __('filament-workflows::enums.failure_strategy.stop'),
                                    FailureStrategies::CONTINUE => __(
                                        'filament-workflows::enums.failure_strategy.continue'
                                    ),
                                    FailureStrategies::TELEGRAM_REPORT => __(
                                        'filament-workflows::enums.failure_strategy.telegram_report'
                                    ),
                                ])
                                ->default(FailureStrategies::STOP)
                                ->native(false),

                            TextInput::make('max_retries')
                                ->label(__('filament-workflows::workflows.fields.max_retries.label'))
                                ->numeric()
                                ->default(3)
                                ->minValue(0)
                                ->maxValue(10),
                        ]),
                ]),
        ]);
    }
}
