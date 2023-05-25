<?php

namespace App\Console\Commands;

use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\User;
use App\Services\amoCRM\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $user = User::query()->first();

        $amoApi = (new Client($user->account))->init();

        $pipelines = $amoApi->service->account->pipelines;

        foreach ($pipelines->toArray() as $pipeline) {

            foreach ($pipeline['statuses']->toArray() as $status) {

                Status::query()->create([
                    'user_id'      => Auth::user()->id,
                    'name'         => $status['name'],
                    'status_id'    => $status['id'],
                    'color'        => $status['color'],
                    'pipeline_id'  => $pipeline->id,
                    'pipeline_name'=> $pipeline->name,
                ]);
            }
        }

        return Command::SUCCESS;
    }
}
