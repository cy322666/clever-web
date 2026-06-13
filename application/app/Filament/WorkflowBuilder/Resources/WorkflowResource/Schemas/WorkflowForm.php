<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Schemas;

use App\Models\Workflows\Workflow;
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
                    Section::make()
                        ->compact()
                        ->columnSpan(2)
                        ->columns(3)
                        ->schema(self::workflowDetailsFields()),

                    Section::make()
                        ->compact()
                        ->columnSpan(1)
                        ->schema(self::executionSettingsFields()),
                ]),
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function workflowDetailsFields(bool $compact = false): array
    {
        return [
            TextInput::make('name')
                ->label(__('filament-workflows::workflows.fields.name.label'))
                ->required()
                ->maxLength(255)
                ->placeholder(__('filament-workflows::workflows.fields.name.placeholder'))
                ->columnSpan($compact ? 2 : 2),

            Select::make('group_name')
                ->label('Группа')
                ->placeholder('Без группы')
                ->options(fn(): array => Workflow::groupOptions())
                ->getOptionLabelUsing(fn(mixed $value): string => (string)$value)
                ->searchable()
                ->native(false)
                ->createOptionModalHeading('Новая группа')
                ->createOptionForm([
                    TextInput::make('name')
                        ->label('Название группы')
                        ->required()
                        ->maxLength(255),
                ])
                ->createOptionUsing(fn(array $data): string => trim($data['name']))
                ->columnSpan($compact ? 2 : 1),

            Textarea::make('description')
                ->label(__('filament-workflows::workflows.fields.description.label'))
                ->rows($compact ? 3 : 2)
                ->placeholder(__('filament-workflows::workflows.fields.description.placeholder'))
                ->columnSpan($compact ? 2 : 3),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function executionSettingsFields(bool $compact = false): array
    {
        return [
            Toggle::make('is_active')
                ->label(__('filament-workflows::workflows.fields.is_active.label'))
                ->default(true)
                ->columnSpan($compact ? 1 : 1),

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
                ->native(false)
                ->columnSpan($compact ? 1 : 1),

            TextInput::make('max_retries')
                ->label(__('filament-workflows::workflows.fields.max_retries.label'))
                ->numeric()
                ->default(3)
                ->minValue(0)
                ->maxValue(10)
                ->columnSpan($compact ? 1 : 1),
        ];
    }
}
