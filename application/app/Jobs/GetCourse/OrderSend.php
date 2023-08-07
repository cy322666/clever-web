<?php

namespace App\Jobs\GetCourse;

use App\Models\GetCourse\Order;
use App\Models\GetCourse\Setting;
use App\Models\User;
use App\Models\Webhook;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use App\Services\ManagerClients\GetCourseManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OrderSend implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private Setting $setting;

    public function __construct(
        public Webhook $webhook,
        public Order $order,
        public User $user,
    )
    {
        $this->onQueue('getcourse_order');

        $this->setting = $user->getcourseSetting;
    }

    public function tags(): array
    {
        return [$this->user->email, 'getcourse_order:'.$this->order->id];
    }

    public function handle(): bool
    {
        try {
            $manager = (new GetCourseManager($this->webhook));

            $amoApi  = $manager->amoApi;
            $account = $manager->amoAccount;

            $responsibleId = $this->setting->responsible_user_id_order ?? $this->setting->responsible_user_id_default;

            if ($this->order->payed_money == $this->order->cost_money) {

                $statusId = $this->setting->status_id_order_close ?? $this->setting->status_id_order;
            } else
                $statusId = $this->setting->status_id_order;

            $contact = Contacts::search([
                'Телефоны' => [$this->order->phone],
                'Почта'    => $this->order->email,
            ], $amoApi);

            if ($contact == null) {

                $contact = Contacts::create($amoApi, $this->order->name);
            }

            $contact = Contacts::update($contact, [
                'Телефоны' => [$this->order->phone],
                'Почта'    => $this->order->email,
                'Ответственный' => $responsibleId,
            ]);

            $lead = $contact->leads->filter(function ($lead) {

                return $lead->status_id !== 142 && $lead->status_id !== 143;

            })?->first();

            if (empty($lead)) {

                $lead = Leads::create($contact, [
                    'status_id' => $statusId,
                    'responsible_user_id' => $contact->responsible_user_id,
                ], 'Новый заказ GetCourse');

            } else {

                $lead->status_id = $this->setting->status_id_order;
                $lead->save();
            }

            $this->order->contact_id = $contact->id;
            $this->order->lead_id = $lead->id;
            $this->order->status = 1;
            $this->order->save();

            Notes::addOne($lead, $this->order->text());

        } catch (\Throwable $exception) {

            $this->order->error = $exception->getMessage().' '.$exception->getFile().' '.$exception->getLine();
            $this->order->save();
        }
        return true;
    }
}
