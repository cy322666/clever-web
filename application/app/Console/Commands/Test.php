<?php

namespace App\Console\Commands;

use App\Models\amoCRM\Field;
use App\Models\Core\Account;
use App\Models\Integrations\Tilda\Form;
use App\Services\amoCRM\Client;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\Http;

use function PHPUnit\Framework\countOf;

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
        $forms = Form::query()
            ->where('user_id', 68)
            ->where('id', '>', 49753)
            ->where('status', false)
            ->where('lead_id', null)
            ->get();

        foreach ($forms as $form) {

            Artisan::call('app:tilda-form-send', [
                'form' => $form->id,
                'account' => 68,
                'setting' => 68,
            ]);
        }

//        dd($customer);
//        $fields = $amoApi->service->ajax()->get('/api/v4/customers/265443?with=companies');


//        $customer = $amoApi->service->customers();


//            $r = $amoApi->service->ajax()->post('/api/v4/customers', [
//            [
//                'name' => 'TEST',
//                'next_price' => 1000,
//                'next_date' => Carbon::now()->timestamp,
//                'periodicity' => 1,
//                'custom_fields_values' => [
//                    [
//                        'field_id' => 436721,
//                        'values' => [
//                            [
//                                'enum_id' => 3051189
//                            ]
//                        ]
//                    ]
//                ],
//                '_embedded' => [
//                    'companies' => [
//                        [
//                            'id' => 30778721
//                        ]
//                    ]
//                ]
//            ]
//            ]);

            dd($r);

        //_embedded->companies[0]->id =

        foreach ($fields->_embedded->custom_fields as $key => $field) {

            if ($field->id == 436721) {

                foreach ($field->enums as $key => $enum) {

                    $products[] = [
                        'id'   => $enum->id,
                        'name' => $enum->value,
                    ];
                }
                $products = $field->enums;
            }
        }


        $companies = $amoApi->service->companies;

        foreach ($companies->toArray() as $company) {

            $companies[] = [
                'id' => $company['id'],
                'name' => $company['name'],
                //проект??

            ];
        }
//        dd($companies);

//        dd($products);

//        try {
//
//            $account = Account::find(72);
//
////            $amoApi = (new Client())
////                ->init();
//        } catch (\Exception $e) {
//            dd($e);
//        }
//
//        $resp = Http::withHeaders([
//            'Content-Type' => 'application/json',
//            'Authorization' => 'Bearer ' . $account->access_token,
//        ])->get('https://' . $account->subdomain . '.amocrm.com/api/v4/contacts?query=eduardomiguelfaria@gmail.com');
//
//        dd($resp->object()->_embedded->contacts[0]->id);
//
////        $lead = $amoApi->service->leads()->find();
//
//        try {
//
//            //id 31416462
//            //10 users
////            $account = $amoApi->service->account->pipelines->toArray();
////            dd($account);
//
////            $account = $amoApi->service->ajax()->post('/api/v4/leads/pipelines', [
////                'name' => 'Производство',
////                ''
////            ]);
//
//        } catch (\Exception $e) {
//
//            dd($e->getMessage());
//        }

//        try {
//            $fields = $amoApi->service
//                ->ajax()
//                ->get('/api/v4/leads/custom_fields')
//                    ->_embedded
//                    ->custom_fields;
//
//            foreach ($fields as $field) {
//
//                Field::query()->updateOrCreate([
//                    'user_id' => 3,
//                    'field_id' => $field->id,
//                ], [
//                    'name' => $field->name,
//                    'type' => $field->type,
//                    'code' => $field->code,
//                    'sort' => $field->sort,
//                    'is_api_only' => $field->is_api_only,
//                    'entity_type' => $field->entity_type,
//                    'enums' => json_encode($field->enums),
//                ]);
//            }
//
//        } catch (\Exception $e) {
//
//            dd($e);
//        }

//        dd($fields);
    }
}
