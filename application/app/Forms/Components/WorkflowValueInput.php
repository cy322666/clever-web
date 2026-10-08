<?php

namespace App\Forms\Components;

use Closure;
use Filament\Forms\Components\TextInput;

/** A single stored value, with either a known option or a workflow expression. */
class WorkflowValueInput extends TextInput
{
    protected string $view = 'forms.components.workflow-value-input';

    protected array|Closure $valueOptions = [];
    protected bool $hasValueOptions = false;

    public function hasValueOptions(): bool { return $this->hasValueOptions; }
    protected int $textareaRows = 0;
    protected array|Closure $valueSuggestions = [];

    public function suggestions(array|Closure $options): static { $this->valueSuggestions = $options; return $this; }
    public function getValueSuggestions(): array { return $this->evaluate($this->valueSuggestions); }

    public function multiline(int $rows = 4): static { $this->textareaRows = $rows; return $this; }
    public function getTextareaRows(): int { return $this->textareaRows; }

    public function options(array|Closure $options): static
    {
        $this->valueOptions = $options;
        $this->hasValueOptions = true;
        return $this;
    }

    public function getValueOptions(): array
    {
        return $this->evaluate($this->valueOptions);
    }
}
