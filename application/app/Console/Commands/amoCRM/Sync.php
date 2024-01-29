<?php

namespace App\Console\Commands\amoCRM;

use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Account;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

class Sync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync {account}';

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
        $account = \App\Models\Core\Account::query()
            ->find($this->argument('account'))
            ->first();

        $amoApi = (new Client($account));

        if (!$amoApi->auth) {

            $amoApi->init();
        }

        if ($amoApi->auth) {

            Account::users($amoApi);
            Account::statuses($amoApi);
            Account::fields($amoApi);
        }
    }
}
