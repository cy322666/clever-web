<?php

namespace App\Forms\Components;

use App\Services\Workflows\WorkflowHttpHeaders;
use Filament\Forms\Components\Field;

class WorkflowHeadersInput extends Field
{
    protected string $view = 'forms.components.workflow-headers-input';

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules([fn () => function (string $attribute, mixed $value, \Closure $fail): void {
            // A whole-object expression can only be checked after resolving it.
            if (is_string($value) && str_starts_with(trim($value), '{{')) return;
            try { WorkflowHttpHeaders::parse($value); }
            catch (\RuntimeException $error) { $fail($error->getMessage()); }
        }]);
    }
}
