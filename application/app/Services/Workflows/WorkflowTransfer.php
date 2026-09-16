<?php

namespace App\Services\Workflows;

use App\Models\Workflows\Workflow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;

final class WorkflowTransfer
{
    public static function encode(string $name, array $definition, array $layout = []): string
    {
        return json_encode(['format'=>'clever-flows','version'=>1,'name'=>$name,
            'definition'=>self::clean($definition), 'layout'=>self::layout($layout),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function decode(string $json): array
    {
        if (strlen($json)>2*1024*1024) throw new \InvalidArgumentException('Размер файла — не более 2 МБ.');
        $file = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($file) || ($file['format'] ?? '') !== 'clever-flows' || ($file['version'] ?? null) !== 1) {
            throw new \InvalidArgumentException('Нужен JSON-файл экспорта «Потоков» версии 1.');
        }
        Validator::make($file, ['name'=>'required|string|max:255','definition'=>'required|array','definition.actions'=>'present|array',
            'definition.tags'=>'sometimes|array|max:20','definition.tags.*'=>'string|max:50','definition.description'=>'sometimes|nullable|string|max:10000',
        ])->validate();
        $definition = array_intersect_key($file['definition'], array_flip(['version','trigger','actions','additional_triggers','connections','description','tags']));
        foreach (WorkflowStartNodes::all($definition) as $start) {
            if (!is_array($start) || !is_string($start['type'] ?? null) || !app(TriggerRegistry::class)->has($start['type']) || !is_array($start['config'] ?? [])) {
                throw new \InvalidArgumentException('Неизвестный или некорректный запуск.');
            }
        }
        foreach (WorkflowGraph::nodes($definition['actions']) as $node) {
            $step = $node['step'];
            if (!is_string($step['type'] ?? null) || !app(ActionRegistry::class)->has($step['type']) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $step['id'])) {
                throw new \InvalidArgumentException('Неизвестная нода или некорректный ID.');
            }
        }
        WorkflowGraph::ordered($definition);
        $definition = self::clean($definition);
        $definition['canvas_layout'] = self::layout($file['layout'] ?? []);
        return ['name'=>trim($file['name']), 'definition'=>$definition];
    }

    public static function import(string $json, ?string $folder = null): Workflow
    {
        abort_unless(Auth::id(), 403);
        if (filled($folder)) WorkflowFolders::assertFolderExists($folder);
        $data = self::decode($json);
        // Never trust owner, activation, IDs or credentials from the uploaded file.
        return Workflow::create($data + ['user_id'=>Auth::id(),'created_by'=>Auth::id(),'is_active'=>false,'group_name'=>$folder ?: null]);
    }

    private static function layout(mixed $layout): array
    {
        if (!is_array($layout) || count($layout)>521) throw new \InvalidArgumentException('Некорректное размещение нод.');
        $result = [];
        foreach ($layout as $id=>$position) {
            if (!preg_match('/^(trigger|trigger:[a-zA-Z0-9_-]+|action:[a-zA-Z0-9_-]+)$/D',(string)$id)
                || !is_array($position) || !is_numeric($position['x'] ?? null) || !is_numeric($position['y'] ?? null)
                || abs((float)$position['x'])>100000 || abs((float)$position['y'])>100000) throw new \InvalidArgumentException('Некорректные координаты ноды.');
            $result[$id] = ['x'=>(float)$position['x'],'y'=>(float)$position['y']];
        }
        return $result;
    }

    private static function clean(array $data): array
    {
        foreach ($data as $key=>$value) {
            if (preg_match('/^(credential_id|bot_token(_encrypted)?|access_token|refresh_token|client_secret|password|authorization|api_key|webhook_secret)$/i',(string)$key)) {
                unset($data[$key]); continue;
            }
            if ($key === 'headers') { $data[$key] = '{}'; continue; }
            if (is_array($value)) $data[$key] = self::clean($value);
        }
        return $data;
    }
}
