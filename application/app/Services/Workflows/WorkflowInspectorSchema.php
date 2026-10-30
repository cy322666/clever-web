<?php

namespace App\Services\Workflows;

use App\Forms\Components\WorkflowHeadersInput;
use App\Forms\Components\WorkflowValueInput;
use Closure;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Livewire\Component as LivewireComponent;

/** The existing PHP schemas supply the browser's controls, labels and dependencies.
 * Nothing here saves a workflow or runs an action. PHP validation remains authoritative.
 */
final class WorkflowInspectorSchema
{
    private array $fields = [];
    private array $defaults = [];

    public function describe(LivewireComponent $page, array $components, array $defaults = []): array
    {
        $this->fields = [];
        $this->defaults = $defaults;
        $schema = Schema::make($page)->components($components);
        $components = $schema->getComponents(withHidden: true);
        $this->index($components);

        return $this->components($components);
    }

    private function index(array $components, string $parent = ''): void
    {
        foreach ($components as $component) {
            if (!$component instanceof Component) continue;
            $path = trim($parent.'.'.($this->raw($component, 'statePath') ?? ''), '.');
            if ($component instanceof Field) $this->fields[$path] = $component;
            $this->index($this->children($component), $component instanceof Repeater ? $path.'.*' : $path);
        }
    }

    private function components(array $components, string $parent = ''): array
    {
        $result = [];
        foreach ($components as $component) {
            if (!$component instanceof Component) continue;
            $name = $this->raw($component, 'statePath') ?? '';
            $path = trim($parent.'.'.$name, '.');
            $kind = match (true) {
                $component instanceof Hidden => 'hidden',
                $component instanceof WorkflowValueInput => 'value',
                $component instanceof WorkflowHeadersInput => 'headers',
                $component instanceof Repeater => 'repeater',
                $component instanceof ToggleButtons => 'buttons',
                $component instanceof Select => 'select',
                $component instanceof Toggle => 'toggle',
                $component instanceof CodeEditor => 'code',
                $component instanceof Textarea => 'textarea',
                $component instanceof Field => 'text',
                $component instanceof \Filament\Schemas\Components\Section => 'section',
                $component instanceof \Filament\Schemas\Components\View => 'view',
                default => 'group',
            };
            $label = $this->raw($component, 'label') ?? (method_exists($component, 'getLabel') ? $component->getLabel() : null);
            $row = ['kind'=>$kind, 'path'=>$path, 'name'=>$name,
                'label'=>$this->rule($component, $label, $path),
                'visible'=>$this->rule($component, $this->raw($component, 'isVisible') ?? true, $path),
                'hidden'=>$this->rule($component, $this->raw($component, 'isHidden') ?? false, $path),
                'visibleJs'=>$component->getVisibleJs(), 'hiddenJs'=>$component->getHiddenJs(),
                'attributes'=>$component->getExtraAttributes(),
                'columns'=>method_exists($component, 'getColumns') ? $component->getColumns() : [],
                'span'=>method_exists($component, 'getColumnSpan') ? $component->getColumnSpan() : []];
            if ($component instanceof Field) {
                $row += ['default'=>$this->rule($component, $this->raw($component, 'defaultState'), $path),
                    'required'=>$this->rule($component, $this->raw($component, 'isRequired') ?? false, $path),
                    'labelHidden'=>$component->isLabelHidden(),
                    'placeholder'=>$this->rule($component, $this->raw($component, 'placeholder'), $path),
                    'helper'=>$this->rule($component, $this->raw($component, 'helperText'), $path)];
            }
            if ($component instanceof Select || $component instanceof ToggleButtons || $component instanceof WorkflowValueInput) {
                $options = $this->raw($component, $component instanceof WorkflowValueInput ? 'valueOptions' : 'options') ?? [];
                $row['options'] = $this->rule($component, $options, $path);
                if ($component instanceof WorkflowValueInput) {
                    $row['hasOptions'] = $this->rule($component, $this->raw($component, 'hasValueOptions'), $path);
                    $row['rows'] = $component->getTextareaRows();
                    $row['relativePath'] = $component->isRelativePath();
                    $row['collectionPath'] = $component->isCollectionPath();
                } elseif ($component instanceof Select) {
                    $row['multiple'] = $component->isMultiple();
                    $row['searchable'] = $component->isSearchable();
                    $row['native'] = $component->isNative();
                    $row['canCreate'] = $component->hasCreateOptionActionFormSchema();
                }
            }
            if ($component instanceof Repeater) {
                if (is_array($row['default']) && !isset($row['default']['dependency'])) $row['default']=array_values($row['default']);
                $row['addLabel'] = $component->getAddActionLabel();
                $row['defaultItems'] = $this->raw($component, 'defaultItems') ?? 0;
                $row['minItems'] = $component->getMinItems();
                $row['maxItems'] = $component->getMaxItems();
                $row['reorderable'] = $component->isReorderable();
            }
            if ($kind === 'text' || $kind === 'value') $row['type'] = method_exists($component, 'getType') ? $component->getType() : 'text';
            if ($kind === 'textarea') $row['rows'] = $component->getRows();
            if ($kind === 'section') {
                $row['label'] = $this->rule($component, $this->raw($component, 'heading'), $path);
                $row['collapsible'] = $component->isCollapsible();
                $row['collapsed'] = $this->rule($component, $this->raw($component, 'isCollapsed') ?? false, $path);
            }
            if ($kind === 'view') $row['html'] = $component->toHtml();
            $row['children'] = $this->components($this->children($component), $component instanceof Repeater ? $path.'.*' : $path);
            $result[] = $row;
        }
        return $result;
    }

    private function raw(object $component, string $property): mixed
    {
        return property_exists($component, $property) ? (new \ReflectionProperty($component, $property))->getValue($component) : null;
    }

    private function children(Component $component): array
    {
        return $component->getChildSchema()?->getComponents(withHidden: true) ?? [];
    }

    /** Resolve a Get path without a Livewire state read, including repeater parents. */
    public static function relative(string $field, string $path): string
    {
        $parts = explode('.', $field);
        array_pop($parts);
        while (str_starts_with($path, '../')) { array_pop($parts); $path = substr($path, 3); }
        return trim(implode('.', $parts).'.'.$path, '.');
    }

    /** Finite selector dependencies become a decision tree, evaluated locally.
     * Array contents / arbitrary expressions stay data, never executable browser code.
     */
    private function rule(Component $component, mixed $value, string $field, array $selected = [], int $depth = 0): mixed
    {
        if (!$value instanceof Closure) return $this->plain($value);
        $reads = [];
        $getter = new class($component, $field, $selected, $this->defaults, $reads) extends Get {
            public function __construct(Component $component, private string $field, private array $selected, private array $defaults, private array &$reads) { parent::__construct($component); }
            public function __invoke(string|Component $path = '', bool $isAbsolute = false): mixed
            {
                $key = $isAbsolute ? (string)$path : WorkflowInspectorSchema::relative($this->field, (string)$path);
                $this->reads[$key] = true;
                return array_key_exists($key, $this->selected) ? $this->selected[$key] : data_get($this->defaults, str_replace('.*', '.0', $key));
            }
        };
        $output = $component->evaluate($value, ['get'=>$getter]);
        $pending = array_values(array_diff(array_keys($reads), array_keys($selected)));
        if ($pending === []) return $this->plain($output);
        $dependency = $pending[0];
        if (str_ends_with($dependency,'__filter_item_field')) return ['copyValue'=>$dependency];
        // The condition's free-text expression is interpreted using the already
        // loaded variable catalogue, not sampled as if it were an enum.
        if (str_ends_with($dependency, '.left')) return ['conditionValues'=>true, 'fallback'=>$this->plain($output)];
        if ($depth >= 8) throw new \LogicException('Too many inspector dependencies: '.$field);
        $cases = [];
        foreach ($this->domain($dependency, $selected) as $candidate) {
            $cases[] = ['value'=>$candidate, 'result'=>$this->rule($component, $value, $field, $selected + [$dependency=>$candidate], $depth+1)];
        }
        $distinct = array_unique(array_map(fn($case) => json_encode($case['result']), $cases));
        if (count($distinct) === 1) return $cases[0]['result'];
        return ['dependency'=>$dependency, 'cases'=>$cases, 'fallback'=>$this->plain($output)];
    }

    private function domain(string $path, array $selected): array
    {
        $values = [null, '', data_get($this->defaults, str_replace('.*', '.0', $path))];
        $field = $this->fields[$path] ?? null;
        if ($field instanceof Select || $field instanceof ToggleButtons || $field instanceof WorkflowValueInput) {
            $raw = $this->raw($field, $field instanceof WorkflowValueInput ? 'valueOptions' : 'options') ?? [];
            $options = $raw instanceof Closure ? $field->evaluate($raw, ['get'=>new class($field, $path, $selected, $this->defaults) extends Get {
                public function __construct(Component $component, private string $field, private array $selected, private array $defaults) { parent::__construct($component); }
                public function __invoke(string|Component $path = '', bool $isAbsolute = false): mixed {
                    $key = $isAbsolute ? (string)$path : WorkflowInspectorSchema::relative($this->field, (string)$path);
                    return $this->selected[$key] ?? data_get($this->defaults, str_replace('.*', '.0', $key));
                }
            }]) : $raw;
            if (is_array($options) && !isset($options['dependency'])) $values = [...$values, ...array_keys($options)];
        }
        if ($field instanceof Toggle) $values = [...$values, false, true];
        if (basename(str_replace('.', '/', $path)) === 'operation') $values = [...$values, ...array_keys(WorkflowAmoReadCatalog::operations())];
        if ($field instanceof Field && !($field instanceof Select || $field instanceof ToggleButtons || $field instanceof WorkflowValueInput)) $values = [...$values, '__present'];
        if (preg_match('/(?:^|\.)(parameters|fields|rules|conditions)$/', $path)) $values = [...$values, [], ['__present'=>true]];
        $unique = [];
        foreach ($values as $value) $unique[json_encode($value)] = $value;
        return array_values($unique);
    }

    private function plain(mixed $value): mixed
    {
        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) return $value->toArray();
        if ($value instanceof \Illuminate\Contracts\Support\Htmlable) return strip_tags($value->toHtml());
        if ($value instanceof \BackedEnum) return $value->value;
        return $value;
    }
}
