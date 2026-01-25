<?php

namespace App\Console\Commands\ContactMerge;

use App\Models\Core\Account;
use App\Models\Integrations\ContactMerge\Setting;
use App\Services\amoCRM\Client;
use App\Services\ContactMerge\ContactMergeService;
use Illuminate\Console\Command;

class RunMerge extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:run-merge {setting_id} {account_id}';

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
        $account = Account::query()->findOrFail($this->argument('account_id'));
        $setting = Setting::query()->findOrFail($this->argument('setting_id'));

        $amoApi = new Client($account);

        $service = new ContactMergeService($setting, $amoApi);

        $service->run();
    }
}
