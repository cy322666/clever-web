<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Test1 extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test1 ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
//        $path = '/Users/integrator/Desktop/log_19.rtf';
        $path = 'https://hub.blackclever.ru/tiw/storage/logs/log-19.log';

        dd(explode("\n", file_get_contents($path)));
    }
}
