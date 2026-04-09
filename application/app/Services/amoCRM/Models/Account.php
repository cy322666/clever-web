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

        DB::transaction(function () use ($users, $userModel) {
            Staff::query()
                ->where('user_id', $userModel->id)
                ->update(['active' => false]);

            foreach ($users as $user) {
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

        DB::transaction(function () use ($pipelines, $user) {
            Status::query()
                ->where('user_id', $user->id)
                ->update(['active' => false]);

            foreach ($pipelines as $pipeline) {
                if ($pipeline->is_archive) {
                    continue;
                }

                foreach ($pipeline->_embedded->statuses as $status) {
                    $pipelineId = (int) $pipeline->id;
                    $statusId   = (int) $status->id;

                    Status::query()->updateOrCreate([
                        'user_id'     => $user->id,
                        'pipeline_id' => $pipelineId,
                        'status_id'   => $statusId,
                    ], [
                        'name' => (string)($status->name ?? ''),
                        'sort' => (int)($status->sort ?? 0),
                        'is_main' => (bool)($pipeline->is_main ?? false),
                        'is_closed' => in_array($statusId, [142, 143], true),
                        'is_won' => $statusId === 142,
                        'is_lost' => $statusId === 143,
                        'color' => (string)($status->color ?? ''),
                        'pipeline_name' => (string)($pipeline->name ?? ''),
                        'active' => true,
                    ]);
                }
            }
        });
    }

    /**
     * @throws Exception
     */
    public static function fields(Client $amoApi, User $user): void
    {
        DB::transaction(function () use ($amoApi, $user) {
            Field::query()
                ->where('user_id', $user->id)
                ->update(['active' => false]);

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

            // Апсерт актуальных полей
            
            foreach ($allFields as $field) {
                $fieldId     = (int) $field->id;
                $entityType  = (string) ($field->entity_type ?? ''); // важен для уникальности

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
        });
    }
}
