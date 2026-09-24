<?php

namespace App\Services\Finder;

use App\Models\amoCRM\Staff;
use App\Models\Workflows\Workflow;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SettingsValidator
{
    public function validate(array $options, int $userId, bool $enabled): array
    {
        Validator::make(['settings' => $options], [
            'settings.working_time' => 'required|boolean',
            'settings.timezone' => 'required|timezone',
            'settings.hours' => 'required|integer|min:0|max:168',
            'settings.minutes' => 'required|integer|min:0|max:59',
            'settings.max_attempts' => 'required|integer|min:1|max:100',
            'settings.schedule' => 'required_if:settings.working_time,1|array|max:21',
            'settings.schedule.*.days' => 'required|array|min:1|max:7',
            'settings.schedule.*.days.*' => 'integer|between:1,7',
            'settings.schedule.*.from' => 'required|date_format:H:i',
            'settings.schedule.*.to' => 'required|date_format:H:i',
            'settings.run_workflow' => 'required|boolean',
            'settings.create_task' => 'required|boolean',
            'settings.run_reply_workflow' => 'required|boolean',
            'settings.task_type_id' => 'required_if:settings.create_task,1|nullable|integer|min:1',
            'settings.task_text' => 'required_if:settings.create_task,1|nullable|string|max:1000',
            'settings.task_due_minutes' => 'required_if:settings.create_task,1|nullable|integer|between:1,10080',
        ])->validate();

        if ((int) $options['hours'] * 60 + (int) $options['minutes'] < 1) {
            throw ValidationException::withMessages(['settings.minutes' => 'Укажите интервал не меньше одной минуты.']);
        }
        if ($enabled && ! $options['run_workflow'] && ! $options['create_task']) {
            throw ValidationException::withMessages(['settings.run_workflow' => 'Выберите сценарий или создание задачи.']);
        }
        foreach (['run_workflow' => 'workflow_id', 'run_reply_workflow' => 'reply_workflow_id'] as $toggle => $field) {
            if ($options[$toggle] && ! Workflow::query()->where('user_id', $userId)->where('is_active', true)->whereKey($options[$field] ?? 0)->exists()) {
                throw ValidationException::withMessages(["settings.$field" => 'Выберите свой активный сценарий.']);
            }
        }
        if ($options['create_task'] && ! empty($options['responsible_user_id']) && ! Staff::query()->where('user_id', $userId)->where('active', true)->where('staff_id', $options['responsible_user_id'])->exists()) {
            throw ValidationException::withMessages(['settings.responsible_user_id' => 'Выберите активного сотрудника своего аккаунта.']);
        }
        if ($options['working_time']) {
            try {
                app(WorkingTime::class)->deadline(\Carbon\CarbonImmutable::now(), ((int) $options['hours'] * 60 + (int) $options['minutes']) * 60, $options);
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['settings.schedule' => $exception->getMessage()]);
            }
        }

        return $options;
    }
}
