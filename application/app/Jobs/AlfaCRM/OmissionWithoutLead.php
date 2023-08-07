<?php

namespace App\Jobs\AlfaCRM;

use App\Models\AlfaCRM\Setting;
use App\Models\AlfaCRM\Transaction;
use App\Models\Webhook;
use App\Services\AlfaCRM\Models\Customer;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use App\Services\ManagerClients\AlfaCRMManager;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OmissionWithoutLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        public Setting $setting,
        public Webhook $webhook,
        public Transaction $transaction,
        public array $data,
    )
    {
        $this->onQueue('alfacrm_omission');
    }

    /**
     * Execute the job.
     *
     * @return false
     * @throws Exception
     */
    public function handle(): bool
    {
        $manager = new AlfaCRMManager($this->webhook);

        $amoApi  = $manager->amoApi;
        $alfaApi = $manager->alfaApi;

        $alfaApi->branchId = $this->transaction->alfa_branch_id;

        $customer = (new Customer($alfaApi))->get($this->transaction->alfa_client_id);

        try {

            $parentTransaction = $this->webhook
                ->user
                ->alfaTransactions()
                ->where('status', 1)
                ->first();

            if (!empty($parentTransaction) && $parentTransaction->amo_lead_id) {

                $lead = $amoApi->service
                    ->leads()
                    ->find($this->transaction->amo_lead_id);

                $contact = $lead->contact;
            }

            if (empty($contact)) {

                $contact = $amoApi->service
                    ->contacts()
                    ->search(Contacts::clearPhone($customer->phone[0] ?? null))
                    ?->first();
            }

            if (empty($contact)) {

                $contact = Contacts::create($amoApi, $customer->name);

                $contact = Contacts::update($contact, [
                    'Телефоны' => $customer->phone,
                    'Почта'    => $customer->email[0],
                ]);

                $lead = Leads::create($contact, [
                    'status_id' => $this->setting->status_omission_1,
                ], 'Новая сделка AlfaCRM');

                $link = Contacts::buildLink($amoApi, $contact->id);

                (new Customer($alfaApi))->update($this->transaction->alfa_client_id, [
                    'web' => $link,
                ]);

                Notes::addOne($lead, 'Синхронизировано с АльфаСРМ, ссылка на клиента '. $link);
            }

            if (empty($lead)) {

                if ($this->setting->status_omission_1) {

                    $pipelineId = $manager->amoAccount
                        ->amoStatuses()
                        ->where('status_id', $this->setting->status_omission_1)
                        ->first()
                        ->pipeline
                        ->pipeline_id;
                } else {
                    $pipelineId = $manager->amoAccount
                        ->amoPipelines()
                        ->where('is_main', true)
                        ->first()
                        ->pipeline_id;
                }

                $lead = $contact->leads->filter(function ($lead) use ($pipelineId) {

                    if ($lead->pipeline_id == $pipelineId &&
                        $lead->status_id !== 142 &&
                        $lead->status_id !== 143) {

                        return $lead;
                    }
                })?->first();
            }

            if (empty($lead)) {
                $this->transaction->error = 'Fail search and create lead';
                $this->transaction->save();

                return false;

            } else {

                $lead->status_id = $this->setting->status_omission_1;
                $lead->save();

                $this->transaction->amo_lead_id = $lead->id;
                $this->transaction->status_id = $lead->status_id;
                $this->transaction->save();

                Notes::addOne($lead, 'Клиент пропустил/отменил пробное в AlfaCRM');
            }
        } catch (\Throwable $exception) {

            $this->transaction->error = $exception->getMessage().' '.$exception->getFile().' '.$exception->getLine();
            $this->transaction->save();

            return false;
        }

        return true;
    }
}
