<?php

namespace App\Console\Commands\Distribution;

use App\Models\amoCRM\Staff;
use App\Models\Core\Account;
use App\Models\Integrations\Distribution\Transaction;
use App\Models\Integrations\Tilda\Form;
use App\Models\Integrations\Tilda\Setting;
use App\Models\User;
use App\Services\amoCRM\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

class ResponsibleSend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:distribution-responsible-send {transaction} {account} {setting} {user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @throws \Exception
     */
    public function handle()
    {
        $transaction = Transaction::find($this->argument('transaction'));
        $account     = Account::find($this->argument('account'));
        $setting     = \App\Models\Integrations\Distribution\Setting::find($this->argument('setting'));
        $user        = User::find($this->argument('user'));

        if (!$setting->active) return;

        $amoApi = (new Client($account))
            ->setDelay(0.5)
            ->initLogs(Env::get('APP_DEBUG'));

        /** @var Transaction $transaction */
        $strategy = $transaction->matchStrategy();

        $strategy->setModels($user, $transaction, $setting);

        //check active leads if needle
        $staffId = $strategy->checkActiveGetStaff($amoApi, $transaction);

        if (!$staffId)
             $staffId = $strategy
                ->setTransactions()
                ->sliceSchedule()
                ->getStaffId();

        $lead = $strategy->changeResponsible($amoApi, $staffId);

        $staff = Staff::query()
            ->where('user_id', $user->id)
            ->where('staff_id', $staffId)
            ->first();

        $transaction->contact_id = $lead->contact->id ?? null;
        $transaction->status = true;
        $transaction->staff_id = $staff->id;
        $transaction->staff_name = $staff->name;
        $transaction->staff_amocrm_id = $staffId;
        $transaction->save();
    }
}
