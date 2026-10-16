<?php

namespace App\Services\Workflows;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Only the editor opts in. Execution always uses current database data. */
final class WorkflowEditorReferenceQuery extends Builder
{
    public static function for(string $model): Builder
    {
        if (!app()->bound('workflow.editor.reference-draft')) return $model::query();
        $instance = new $model;
        $query = (new self($instance->getConnection()->query()))->setModel($instance);
        foreach ($instance->getGlobalScopes() as $key => $scope) $query->withGlobalScope($key, $scope);
        return $query;
    }

    public function get($columns = ['*']): Collection
    {
        $draft = app('workflow.editor.reference-draft');
        $key = hash('sha256', json_encode([auth()->id(), $this->model::class, $this->toSql(), $this->getBindings(), $columns]));
        if (!array_key_exists($key, $draft->rows)) {
            $draft->rows[$key] = parent::get($columns)->map(fn ($row) => $row->getAttributes())->all();
        }
        return $this->model->newCollection(array_map(fn ($row) => $this->model->newFromBuilder($row), $draft->rows[$key]));
    }
}
