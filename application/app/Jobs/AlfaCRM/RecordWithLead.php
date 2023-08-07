<?php

namespace App\Jobs\AlfaCRM;

use App\Models\AlfaCRM\Customer;
use App\Models\AlfaCRM\Field;
use App\Models\AlfaCRM\Setting;
use App\Models\AlfaCRM\Transaction;
use App\Models\Webhook;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Notes;
use App\Services\ManagerClients\AlfaCRMManager;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordWithLead implements ShouldQueue
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
        $this->onQueue('alfacrm_with_lead');
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

        try {

            $lead = $amoApi->service
                ->leads()
                ->find($this->data['id']);

            $contact = $lead->contact;

            if (!$contact) {

                $this->transaction->error = 'Lead without contact';
                $this->transaction->save();

                return false;
            }

            $alfaApi->branchId = $this->setting::getBranchId($lead, $contact, $manager->alfaAccount, $this->setting);

            $stageId = $this->setting->stage_record_1;

            if (!$stageId) {

                $this->transaction->error = 'Stage id not select';
                $this->transaction->save();

                return false;
            }

            $fieldValues = $this->setting->getFieldValues($lead, $contact, $manager->amoAccount, $manager->alfaAccount);

            $customer = $this->setting->customerUpdateOrCreate($fieldValues, $alfaApi, true);

            if (is_string($customer) === true) {

                $this->transaction->error = $customer;
                $this->transaction->save();

                return false;
            }

            Field::prepareCreateLead($fieldValues, $amoApi, $alfaApi, $contact, $stageId);

            $this->transaction->alfa_client_id = $customer->id;
            $this->transaction->fields = $fieldValues;
            $this->transaction->alfa_branch_id = $alfaApi->branchId;

            Notes::addOne($lead, 'Синхронизировано с АльфаСРМ, ссылка на лид '.Customer::buildLink($alfaApi, $customer->id));

        } catch (\Throwable $exception) {

            $this->transaction->error = $exception->getMessage().' '.$exception->getFile().' '.$exception->getLine();
            $this->transaction->save();

            return false;
        }
        $this->transaction->save();

        return true;
    }
}
