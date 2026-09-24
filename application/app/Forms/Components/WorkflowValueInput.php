<?php

namespace App\Forms\Components;

use Closure;
use Filament\Forms\Components\TextInput;

/** A single stored value, with either a known option or a workflow expression. */
class WorkflowValueInput extends TextInput
{
    protected string $view = 'forms.components.workflow-value-input';

    protected array|Closure $valueOptions = [];
    protected bool|Closure $hasValueOptions = false;

    public function hasValueOptions(): bool { return (bool) $this->evaluate($this->hasValueOptions); }
    protected int $textareaRows = 0;
    protected array|Closure $valueSuggestions = [];

    public function suggestions(array|Closure $options): static { $this->valueSuggestions = $options; return $this; }
    public function getValueSuggestions(): array { return $this->evaluate($this->valueSuggestions); }

    public function multiline(int $rows = 4): static { $this->textareaRows = $rows; return $this; }
    public function getTextareaRows(): int { return $this->textareaRows; }

    public function options(array|Closure $options, bool|Closure $condition = true): static
    {
        $this->valueOptions = $options;
        $this->hasValueOptions = $condition;
        return $this;
    }

    public function getValueOptions(): array
    {
        return $this->evaluate($this->valueOptions);
    }
}
