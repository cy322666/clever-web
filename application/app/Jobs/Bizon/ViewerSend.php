<?php

namespace App\Jobs\Bizon;

use App\Models\Bizon\Setting;
use App\Models\Bizon\Viewer;
//use App\Services\Bizon365\ViewerSender;
use App\Models\User;
use App\Models\Webhook;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Notes;
use App\Services\ManagerClients\BizonManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ViewerSend implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Количество попыток выполнения задания.
     *
     * @var int
     */
    public int $tries = 1;
    /**
     * Количество секунд, в течение которых задание может выполняться до истечения тайм-аута.
     *
     * @var int
     */
//    public int $timeout = 90;

    /**
     * Количество секунд ожидания перед повторной попыткой выполнения задания.
     *
     * @var int
     */
    public int $backoff = 10;

    /**
     * Indicate if the job should be marked as failed on timeout.
     *
     * @var bool
     */
    public bool $failOnTimeout = true;

    /**
     * Количество секунд, по истечении которых уникальная блокировка задания будет снята.
     *
     * @var int
     */
//    public int $uniqueFor = 5;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        private Webhook $webhook,
        private Viewer $viewer,
        private Setting $setting,
        private User $user,
    )
    {
        $this->onQueue('bizon_export');
    }

    /**
     * Получить посредника, через которого должно пройти задание.
     *
     * @return array
     */
    public function middleware(): array
    {
        return [];
    }

    public function uniqueId()
    {
        return $this->viewer->id;
    }

    /**
     * Execute the job.
     * @return bool
     * @var Client $amoApi
     */
    // artisan queue:listen database --queue=bizon_export --sleep=3
    public function handle()
    {
        try {
            Log::channel('bizon')->info(__METHOD__.' > начало отправки viewer id : '.$this->viewer->id);

            $manager = new BizonManager($this->webhook);

            $amoApi   = $manager->amoApi;

            $statusId = $this->viewer->getStatusId($this->setting);

            $pipelineId = $manager->amoAccount
                ->amoStatuses()
                ->where('status_id', $statusId)
                ->first()
                ->pipeline
                ->pipeline_id;

            $responsibleId = $this->viewer->getResponsibleType($this->setting);

            $tag = $this->viewer->getTagType($this->setting);

            $contact = Contacts::search([
                'Телефоны' => [$this->viewer->phone],
                'Почта'    => $this->viewer->email,
            ], $amoApi);

            if (!$contact) {
                $contact = Contacts::create($amoApi, $this->viewer->username);
            }

            $contact = Contacts::update($contact, [
                'Телефоны' => [$this->viewer->phone],
                'Почта'    => $this->viewer->email,
                'Ответственный' => $responsibleId ?? $contact->responsible_user_id,
            ]);

            $lead = Leads::search($contact, $amoApi, $pipelineId);

            if (!$lead) {

                $lead = Leads::create($contact, [
                    'status_id' => $statusId,
                ], 'Новый лид с Вебинара');
            }

            $lead = Leads::update($lead, [
                'status_id' => $statusId,
                'responsible_user_id' => $responsibleId,
            ], []);

            $lead->attachTags([
                $this->setting->tag,
                $tag,
            ]);
            $lead->save();

            Notes::addOne($lead, $this->viewer->createTextForNote());

            $this->viewer->lead_id = $lead->id;
            $this->viewer->contact_id = $contact->id;
            $this->viewer->status = 1;
            $this->viewer->save();

            $webinar = $this->viewer->webinar;

            if ($webinar->viewers()->count() == 0) {

                $webinar->status = 1;
                $webinar->save();

                Log::channel('bizon')->info(__METHOD__.' > выгрузка webinar_id : '.$webinar->id.' завершена');
            }

            Log::channel('bizon')->info(__METHOD__.' > отправка закончена viewer id : '.$this->viewer->id);

            return true;

        } catch (\Throwable $exception) {

            $this->viewer->error = $exception->getMessage().' '.$exception->getFile().' '.$exception->getLine();
            $this->viewer->save();

            return false;
        }
    }
}
