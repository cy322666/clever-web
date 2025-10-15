<?php

namespace App\Console\Commands\Clever;

use App\Models\Clever\Company;
use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use Illuminate\Console\Command;

class Companies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:companies-sync';

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
        $amoApi = new Client(Account::query()->find(3));

        try {
            $companiesCollection = $amoApi->service->companies;

            foreach ($companiesCollection as $company) {

                Company::query()->updateOrCreate([
                    'company_id' => $company->id,
                ], [
                    'name' => $company->name,
                ]);
            }

        } catch (\Throwable $e) {
            logger()->error('Ошибка при загрузке компаний: ' . $e->getMessage());
        }
    }
}
