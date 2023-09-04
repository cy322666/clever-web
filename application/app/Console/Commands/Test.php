<?php

namespace App\Console\Commands;

use App\Models\amoCRM\Field;
use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Auth;

class Test extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test';

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
        try {
            $amoApi = (new Client(Account::find(3)))
                ->init();
        } catch (\Exception $e) {
            dd($e);
        }

        try {
            $fields = $amoApi->service
                ->ajax()
                ->get('/api/v4/leads/custom_fields')
                    ->_embedded
                    ->custom_fields;

            foreach ($fields as $field) {

                Field::query()->updateOrCreate([
                    'user_id' => 3,
                    'field_id' => $field->id,
                ], [
                    'name' => $field->name,
                    'type' => $field->type,
                    'code' => $field->code,
                    'sort' => $field->sort,
                    'is_api_only' => $field->is_api_only,
                    'entity_type' => $field->entity_type,
                    'enums' => json_encode($field->enums),
                ]);
            }

        } catch (\Exception $e) {

            dd($e);
        }

        dd($fields);
    }
}
