<?php

namespace App\Services\amoCRM\Models;

use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\User;
use App\Services\amoCRM\Client;
use Exception;
use Illuminate\Support\Facades\DB;

class Account
{
    public static function users(Client $amoApi, User $userModel): void
    {
        $users = $amoApi->service->account->users;

        $seenStaffIds = [];

        DB::transaction(function () use ($users, $userModel, &$seenStaffIds) {
            foreach ($users as $user) {
                $seenStaffIds[] = (int) $user->id;

                Staff::query()->updateOrCreate([
                    'user_id'  => $userModel->id,
                    'staff_id' => (int) $user->id,
                ], [
                    'group_id'   => (int) ($user->group->id ?? 0),
                    'group_name' => (string) ($user->group->name ?? ''),
                    'name'       => (string) ($user->name ?? ''),
                    'active'     => (bool) ($user->is_active ?? true),
                    'login'      => (string) ($user->login ?? ''),
                    'phone'      => (string) ($user->phone ?? ''),
                    'admin'      => (bool) ($user->is_admin ?? false),
                ]);
            }

            // Всё, чего нет в API — деактивируем
            Staff::query()
                ->where('user_id', $userModel->id)
                ->whereNotIn('staff_id', $seenStaffIds ?: [-1])
                ->update(['active' => false]);
        });
    }

    /**
     * @throws Exception
     */
    public static function statuses(Client $amoApi, User $user): void
    {
        $pipelines = $amoApi->service->ajax()
            ->get('/api/v4/leads/pipelines')
            ->_embedded
            ->pipelines;

        // Ключ статуса: pipeline_id + status_id
        $seenPairs = []; // ["{pipelineId}:{statusId}", ...]

        DB::transaction(function () use ($pipelines, $user, &$seenPairs) {
            foreach ($pipelines as $pipeline) {
                if ($pipeline->is_archive) {
                    continue;
                }

                foreach ($pipeline->_embedded->statuses as $status) {
                    $pipelineId = (int) $pipeline->id;
                    $statusId   = (int) $status->id;

                    $seenPairs[] = $pipelineId . ':' . $statusId;

                    Status::query()->updateOrCreate([
                        'user_id'     => $user->id,
                        'pipeline_id' => $pipelineId,
                        'status_id'   => $statusId,
                    ], [
                        'name'          => (string) ($status->name ?? ''),
                        'is_main'       => (bool) ($pipeline->is_main ?? false),
                        'color'         => (string) ($status->color ?? ''),
                        'pipeline_name' => (string) ($pipeline->name ?? ''),
                        'active'        => true,
                    ]);
                }
            }

            // Деактивация отсутствующих.
            // Важно: whereNotIn по паре колонок нельзя напрямую,
            // поэтому делаем через concat key. (Под Postgres/MySQL работает)
            // Если хочешь совсем железобетон — сделаем через временную таблицу/CTE.
            Status::query()
                ->where('user_id', $user->id)
                ->whereRaw("(pipeline_id::text || ':' || status_id::text) NOT IN (" . self::placeholders(count($seenPairs)) . ")", $seenPairs ?: ['-1:-1'])
                ->update(['active' => false]);
        });
    }

    /**
     * @throws Exception
     */
    public static function fields(Client $amoApi, User $user): void
    {
        DB::transaction(function () use ($amoApi, $user) {
            // Собираем все поля по 3 сущностям
            $allFields = [];

            // leads (пагинация)
            for ($i = 1; ; $i++) {
                $resp = $amoApi->service->ajax()->get('/api/v4/leads/custom_fields', ['page' => $i]);

                $fields = $resp->_embedded->custom_fields ?? null;
                if (empty($fields)) {
                    break;
                }

                foreach ($fields as $field) {
                    $allFields[] = $field;
                }
            }

            // contacts
            $fields = $amoApi->service->ajax()->get('/api/v4/contacts/custom_fields')->_embedded->custom_fields ?? [];
            foreach ($fields as $field) {
                $allFields[] = $field;
            }

            // companies
            $fields = $amoApi->service->ajax()->get('/api/v4/companies/custom_fields')->_embedded->custom_fields ?? [];
            foreach ($fields as $field) {
                $allFields[] = $field;
            }

            // Апсерт + список увиденных
            $seenFieldKeys = []; // entity_type:field_id

            foreach ($allFields as $field) {
                $fieldId     = (int) $field->id;
                $entityType  = (string) ($field->entity_type ?? ''); // важен для уникальности
                $key         = $entityType . ':' . $fieldId;

                $seenFieldKeys[] = $key;

                Field::query()->updateOrCreate([
                    'user_id'      => $user->id,
                    'field_id'     => $fieldId,
                    'entity_type'  => $entityType,
                ], [
                    'name'        => (string) ($field->name ?? ''),
                    'type'        => (string) ($field->type ?? ''),
                    'code'        => $field->code ?? null,
                    'sort'        => (int) ($field->sort ?? 0),
                    'is_api_only' => (bool) ($field->is_api_only ?? false),
                    'enums'       => json_encode($field->enums ?? null, JSON_UNESCAPED_UNICODE),
                    'active'      => true,
                ]);
            }

            // Деактивируем то, чего нет в amo
            Field::query()
                ->where('user_id', $user->id)
                ->whereRaw("(entity_type || ':' || field_id::text) NOT IN (" . self::placeholders(count($seenFieldKeys)) . ")", $seenFieldKeys ?: ['-1:-1'])
                ->update(['active' => false]);
        });
    }

    private static function placeholders(int $count): string
    {
        if ($count <= 0) {
            return '?';
        }
        return implode(',', array_fill(0, $count, '?'));
    }
}
