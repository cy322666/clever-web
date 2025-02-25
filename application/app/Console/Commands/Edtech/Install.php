<?php

namespace App\Console\Commands\Edtech;

use App\Models\Core\Account;
use App\Models\User;
use App\Services\amoCRM\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;

class Install extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:install {user_id}';

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
        //создаем воронки +-
        //создаем поля +
        //создаем триггеры
        //подключаем виджеты
        //обязательность полей?

        //добавить столбец индастри в юзере с параметрами ответа
        //исходя из них устанавливать интеграции, ссылкм на норм решения с рефералками
        //давать ссылку на интеграцию а точнее в доке и там доступы? или на почту все таки??

        //создаем сделку - пример

        //затем обновляем сущности
        //синхронизируем новый акк (статусы поля и тд)

        $user = User::query()->find($this->argument('user_id'));

        $amoApi = (new Client($user->account))
            ->setDelay(0.5)
            ->initLogs(Env::get('APP_DEBUG'));

//        Artisan::call('app:create-fields', ['user_id' => $this->argument('user_id')]);
    }
}
