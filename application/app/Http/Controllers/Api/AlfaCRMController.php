<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\AlfaCRM\CameWithoutLead;
use App\Jobs\AlfaCRM\OmissionWithoutLead;
use App\Jobs\AlfaCRM\RecordWithLead;
use App\Models\Integrations\Alfa\Transaction;
use App\Models\Integrations\Alfa\Setting;
use App\Models\User;
use App\Services\AlfaCRM\Client as alfaApi;
use App\Services\AlfaCRM\Models\Lesson;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AlfaCRMController extends Controller
{
    public function record(User $user, Request $request)
    {
        if ($request->leads) {

            $data = $request->leads['status'][0] ?? $request->leads['add'][0];

            $transaction = Transaction::query()->create([
                'user_id' => $user->id,
                'comment' => 'record',
                'status'  => Setting::RECORD,
                'amo_lead_id' => $data['id'],
                'status_id'   => $data['status_id'],
            ]);

            RecordWithLead::dispatch($transaction, $user->alfacrm_settings, $user->account);
        }
    }

    /**
     * @throws GuzzleException
     */
    public function came(User $user, Request $request)
    {
        $setting = $user->alfacrm_settings ;

        $alfaApi = (new alfaApi($setting))
            ->setBranch($request->branch_id)
            ->init();

        $lesson = (new Lesson($alfaApi))->get($request->entity_id);

        Log::info(__METHOD__, [$lesson]);

//        if ($lesson &&
//            $lesson->status == Lesson::LESSON_CAME_TYPE_ID &&
//            $lesson->lesson_type_id == Lesson::LESSON_TYPE_ID) {

        if (!empty($lesson->customer_ids[0])) {

            $transaction = Transaction::query()->create([
                'user_id' => $user->id,
                'comment' => 'came',
                'status'  => Setting::CAME,
                'alfa_branch_id' => $request->branch_id,
                'alfa_lesson_id' => $request->entity_id,
                'alfa_client_id' => $lesson->customer_ids[0] ?? null,
            ]);

            CameWithoutLead::dispatch($transaction, $setting, $user->account);
        }
//        }
    }

    /**
     * @throws GuzzleException
     */
    public function omission(User $user, Request $request)
    {
        $setting = $user->alfacrm_settings;

        $alfaApi  = (new alfaApi($setting))
            ->setBranch($request->branch_id)
            ->init();

        $lesson = (new Lesson($alfaApi))->get($request->entity_id, Lesson::LESSON_OMISSION_TYPE_ID);

        Log::info(__METHOD__, [$lesson]);

//        if ($lesson &&
//            $lesson->status == Lesson::LESSON_OMISSION_TYPE_ID &&
//            $lesson->lesson_type_id == Lesson::LESSON_TYPE_ID) {

        if (!empty($lesson->customer_ids[0])) {

            $transaction = Transaction::query()
                ->create([
                    'alfa_branch_id' => $request->branch_id,
                    'alfa_client_id' => $lesson->customer_ids[0] ?? null,
                    'alfa_lesson_id' => $request->entity_id,
                    'user_id' => $user->id,
                    'comment' => 'omission',
                    'status'  => Setting::OMISSION,
                ]);

            OmissionWithoutLead::dispatch($transaction, $setting, $user->account);
        }
//        }
    }

    public function archive()
    {
        //TODO
    }
}
