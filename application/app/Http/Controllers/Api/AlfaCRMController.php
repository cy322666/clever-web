<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\AlfaCRM\CameRequest;
use App\Http\Requests\Api\AlfaCRM\OmissionRequest;
use App\Http\Requests\Api\AlfaCRM\RecordRequest;
use App\Jobs\AlfaCRM\CameWithoutLead;
use App\Jobs\AlfaCRM\OmissionWithoutLead;
use App\Jobs\AlfaCRM\RecordWithLead;
use App\Jobs\AlfaCRM\RecordWithoutLead;
use App\Models\AlfaCRM\Setting;
use App\Models\AlfaCRM\Transaction;
use App\Models\User;
use App\Models\Webhook;
use App\Services\AlfaCRM\Client as alfaApi;
use App\Services\AlfaCRM\Mapper;
use App\Services\AlfaCRM\Models\Lesson;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class AlfaCRMController extends Controller
{
    private alfaApi $alfaApi;

    public function record(Webhook $webhook, RecordRequest $request, Transaction $transaction)
    {
        $data = $request->leads['status'][0] ?? $request->leads['add'][0];

        try {
            $setting = $webhook
                ->user
                ->alfaSetting()
                ->firstOrFail();

            if($setting->checkStatus('record', $data['status_id'])) {

                $transaction->setRecordData($data, $webhook);

                if ($setting->work_lead == true) {

                    RecordWithLead::dispatch($setting, $webhook, $transaction, $data);
                } else
                    RecordWithoutLead::dispatch($setting, $webhook, $transaction, $data);
            }
        } catch (ModelNotFoundException $exception) {

            $transaction->error = 'Not found settings';
            $transaction->save();

        } catch (\Throwable $exception) {

            $transaction->error = $exception->getMessage().' '.$exception->getFile().' '.$exception->getLine();
            $transaction->save();
        }
    }

    public function came(Webhook $webhook, CameRequest $request)
    {
        try {
            $this->alfaApi = (new alfaApi($webhook->user->alfaAccount()))->init();
            $this->alfaApi->branchId = $request->branch_id;

            $setting = $webhook->user
                ->alfaSetting()
                ->firstOrFail();

            $lesson = (new Lesson($this->alfaApi))
                ->get(
                    $request->entity_id,
                    Lesson::LESSON_CAME_TYPE_ID,
                );

            if ($lesson) {

                if ($lesson->status == Lesson::LESSON_CAME_TYPE_ID &&
                    $lesson->lesson_type_id == Lesson::LESSON_TYPE_ID) {

                    $transaction = $webhook->user
                        ->alfaTransactions()
                        ->create([
                            'alfa_branch_id' => $request->branch_id,
                            'alfa_client_id' => $lesson->customer_ids[0],
                            'user_id' => $webhook->user->id,
                        ]);

                    $transaction->setCameData($request->toArray(), $webhook);

                    CameWithoutLead::dispatch($setting, $webhook, $transaction, $request->toArray());
                }
            } else {
                Log::channel('alfacrm')->error('Lesson dont get by id : ', [
                    'branch_id' => $this->alfaApi->branchId,
                    'entity_id' => $request->entity_id,
                    'status'    => Lesson::LESSON_CAME_TYPE_ID,
                ]);
            }
        } catch (\Throwable $exception) {

            if (!empty($transaction)) {
                $transaction->error = $exception->getMessage().' '.$exception->getFile().' '.$exception->getLine();
                $transaction->save();
            } else
                Log::channel('alfacrm')->error($request->path().' '.$exception->getMessage().' '.$exception->getFile().' '.$exception->getLine());
        }
    }

    public function omission(Webhook $webhook, OmissionRequest $request)
    {
        try {
            $this->alfaApi = (new alfaApi($webhook->user->alfaAccount()))->init();
            $this->alfaApi->branchId = $request->branch_id;

            $setting = $webhook->user
                ->alfaSetting()
                ->firstOrFail();

            $lesson = (new Lesson($this->alfaApi))
                ->get(
                    $request->entity_id,
                    Lesson::LESSON_OMISSION_TYPE_ID,
                );

            if ($lesson) {

                if ($lesson->status == Lesson::LESSON_OMISSION_TYPE_ID &&
                    $lesson->lesson_type_id == Lesson::LESSON_TYPE_ID) {

                    $transaction = $webhook->user
                        ->alfaTransactions()
                        ->create([
                            'alfa_branch_id' => $request->branch_id,
                            'alfa_client_id' => $lesson->customer_ids[0],
                            'user_id' => $webhook->user->id,
                        ]);

                    $transaction->setOmissionData($request->toArray(), $webhook);

                    OmissionWithoutLead::dispatch($setting, $webhook, $transaction, $request->toArray());
                }
            }
        } catch (\Throwable $exception) {

            if (!empty($transaction)) {
                $transaction->error = $exception->getMessage().' '.$exception->getFile().' '.$exception->getLine();
                $transaction->save();
            } else
                Log::channel('alfacrm')->error($request->path().' '.$exception->getMessage().' '.$exception->getFile().' '.$exception->getLine());
        }
    }
}
