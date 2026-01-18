<?php

namespace App\Observers\Triggers;

use App\Models\Integrations\Triggers\Event;
use App\Models\Integrations\Triggers\Setting;
use App\Models\Integrations\Triggers\Task;

class EventObserver
{
    /**
     * Handle the Event "created" event.
     */
    public function created(Event $event): void
    {
        //разделяем на блоки
        //создаем задачу по каждому блоку в триггере

        $settings = Setting::query()
            ->where('start_trigger', $event->type)
            ->where('active', true)
            ->where('user_id', $event->user_id)
            ->get();

        foreach ($settings as $setting) {

            $conditions = json_decode($setting->conditions, true);

            //[{"condition_param":"before_status","condition_type":"=","condition_value":"field_lead"}]
            foreach ($conditions as $key => $condition) {

                Task::query()
                    ->create([
                        'event_type' => $setting->start_trigger,
                        'block' => $key,
                        'event_id' => $event->id,
                        'trigger_id' => $setting->id,
                        'user_id' => $setting->user_id,
                        'account_id' => $event->account_id,
                        'setting_id' => $setting->id,
                    ]);
            }
        }
    }

    /**
     * Handle the Event "updated" event.
     */
    public function updated(Event $event): void
    {
        //
    }

    /**
     * Handle the Event "deleted" event.
     */
    public function deleted(Event $event): void
    {
        //
    }

    /**
     * Handle the Event "restored" event.
     */
    public function restored(Event $event): void
    {
        //
    }

    /**
     * Handle the Event "force deleted" event.
     */
    public function forceDeleted(Event $event): void
    {
        //
    }
}
