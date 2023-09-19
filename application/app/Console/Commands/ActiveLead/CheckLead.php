<?php

namespace App\Console\Commands\ActiveLead;

use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use App\Models\Integrations\ActiveLead\Lead;
use App\Models\Integrations\ActiveLead\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Leads;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Ufee\Amo\Base\Collections\Collection;

class CheckLead extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-lead {model} {setting} {account}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle()
    {
        $model   = Lead::find($this->argument('model'));
        $account = Account::find($this->argument('account'));
        $setting = Setting::find($this->argument('setting'));

        $amoApi = (new Client($account))
            ->setDelay(0.2)
            ->initLogs(Env::get('APP_DEBUG'));

        $lead = $amoApi->service
            ->leads()
            ->find($model->lead_id);

        $contact = $lead->contact;

        if ($contact) {

            if ($setting->check_pipeline) {

                $pipelineId = Status::query()
                    ->find($setting->pipeline_id_check)
                    ->pipeline_id;

                $leads = Leads::searchAll($contact, $amoApi, $pipelineId);

            } else
                $leads = Leads::searchAll($contact, $amoApi);

            /** @var Collection $leads */
            if ($leads->count() > 1) {

                $lead->attachTag($setting->tag);
                $lead->save();
            }

            $model->is_active   = $leads->count() > 1;
            $model->count_leads = $leads->count();
            $model->contact_id  = $contact->id;

        } else {
            $model->is_active   = false;
            $model->count_leads = 1;
        }

        $model->status = 1;
        $model->save();
    }
}
