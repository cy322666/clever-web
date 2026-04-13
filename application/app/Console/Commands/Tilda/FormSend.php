<?php

namespace App\Console\Commands\Tilda;

use App\Models\amoCRM\Field;
use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Core\Account;
use App\Models\Integrations\Tilda\Form;
use App\Models\Integrations\Tilda\FormNote;
use App\Models\Integrations\Tilda\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use App\Services\amoCRM\Models\Tags;
use Illuminate\Console\Command;
use Throwable;

class FormSend extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:tilda-form-send {form} {account} {setting}';

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
        $form = Form::find($this->argument('form'));
        $account = Account::find($this->argument('account'));
        $settingModel = Setting::find($this->argument('setting'));

        if (!$form || !$account || !$settingModel) {
            $this->error('Tilda form send: form/account/setting not found');
            return self::FAILURE;
        }

        $body = json_decode($form->body);
        $settingsBySite = json_decode($settingModel->settings, true);
        $setting = is_array($settingsBySite) ? ($settingsBySite[$form->site] ?? null) : null;

        if (!$body || !is_array($setting)) {
            $this->error('Tilda form send: invalid form body or missing site settings');
            return self::FAILURE;
        }

        $amoApi = (new Client($account))->setDelay(0.5);

        $objectStatus = !empty($setting['status_id'])
            ? Status::getObject($setting['status_id'])
            : null;

        if (!$objectStatus) {
            $this->error('Tilda form send: status not configured');
            return self::FAILURE;
        }

        $responsibleId = Staff::query()
            ->find($setting['responsible_user_id'] ?? null)
            ?->staff_id;

        $lead = null;

        $phone = Form::getValueForKey('phone', $body, $setting);
        $phone = Contacts::clearPhone($phone);

        $contact = Contacts::search([
            'Телефоны' => [$phone],
            'Почта'    => Form::getValueForKey('email', $body, $setting),
        ], $amoApi);

        if ($contact == null) {

            $contact = Contacts::create($amoApi, Form::getValueForKey('name', $body, $setting) ?? 'Неизвестно');

        } elseif ($setting['is_union'] == 'yes') {

            $lead = Leads::search($contact, $amoApi, $objectStatus->pipeline_id);

            if ($lead) {
                $lead = $amoApi->service->leads()->find($lead->id);

                if ($lead) {
                    $responsibleId = $lead->responsible_user_id;
                }
            }
        }

        $contactFields = [
            'Телефоны' => [$phone],
            'Почта'    => Form::getValueForKey('email', $body, $setting),
            'Ответственный' => $responsibleId,
        ];

        $contact = $this->updateContactWithRetry($contact, $contactFields, $amoApi);

        if (empty($lead)) {

            $lead = Leads::createPrepare($contact, [
                'responsible_user_id' => $responsibleId,
                'pipeline_id' => $objectStatus->pipeline_id,
                'status_id' => $objectStatus->status_id,
            ], 'Новая заявка с Тильды');
        }

        $applyLeadChanges = function ($currentLead) use ($setting, $form, $body) {
            if (($setting['utms'] ?? 'merge') == 'rewrite') {
                $currentLead = Leads::setRewriteUtms($currentLead, $form->parseCookies());
            } else {
                $currentLead = Leads::setUtms($currentLead, $form->parseCookies());
            }

            if (isset($setting['fields'])) {
                $currentLead = $form->setCustomFields($currentLead, $setting['fields']);
            }

            if (!empty($setting['products']) && $setting['products'] == 'yes' &&
                !empty($body->payment) && !empty($body->payment->products)) {
                $fieldProducts = Field::query()
                    ->where('field_id', $setting['field_products'] ?? null)
                    ->first();

                $amount = $body->payment->amount;
                $name = null;

                foreach ($body->payment->products as $product) {
                    try {
                        $name .= str_replace(['\u0026quot;', '&quot;'], '"', $product->name);

                        if (!empty($product->options) && count($product->options) > 0) {
                            foreach ($product->options as $option) {
                                $name .= ' ' . $option->variant . ' ';
                            }
                        }
                    } catch (Throwable) {
                    }
                }

                if ($fieldProducts) {
                    $currentLead->cf($fieldProducts->name)->setValue($name);
                    $currentLead = $form->setQuantity(
                        $currentLead,
                        $setting['fields'] ?? [],
                        count($body->payment->products)
                    );
                }

                if (isset($setting['fields'])) {
                    $currentLead = $form->setCustomFieldsProduct($currentLead, $setting['fields']);
                }

                $currentLead->sale = $amount;
            }

            return $currentLead;
        };

        $lead = $applyLeadChanges($lead);

        $lead = $this->saveLeadWithRetry($lead, $amoApi, $applyLeadChanges);

        $lead = $amoApi->service->leads()->find($lead->id);

        if (!empty($setting['products']) && $setting['products'] == 'yes' &&
            !empty($body->payment) && !empty($body->payment->products))

            Notes::addOne($lead, FormNote::products($body->payment->products));

        Notes::addOne($lead, FormNote::create($form));

        $lead = $amoApi->service->leads()->find($lead->id);

        $tags = array_values(array_filter([
            $setting['tag'] ?? null,
            'tilda',
        ], static fn($tag) => is_string($tag) ? trim($tag) !== '' : !empty($tag)));

        if (!empty($tags)) {
            $lead = $this->addTagWithRetry($lead, $tags, $amoApi);
        }

        $form->contact_id = $contact->id;
        $form->lead_id = $lead->id;
        $form->status = 1;
        $form->save();

        return self::SUCCESS;
    }

    private function updateContactWithRetry($contact, array $fields, Client $amoApi)
    {
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return Contacts::update($contact, $fields);
            } catch (Throwable $e) {
                if (!$this->isStaleUpdateError($e) || empty($contact?->id) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                $this->warn('Tilda form send: contact stale update conflict, retrying');
                usleep(200000 * $attempt);

                $freshContact = $amoApi->service->contacts()->find($contact->id);

                if (!$freshContact) {
                    throw $e;
                }

                $contact = $freshContact;
            }
        }

        return $contact;
    }

    private function saveLeadWithRetry($lead, Client $amoApi, ?callable $reapply = null)
    {
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $lead->save();

                return $lead;
            } catch (Throwable $e) {
                if (!$this->isStaleUpdateError($e) || empty($lead?->id) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                $this->warn('Tilda form send: lead stale update conflict, retrying');
                usleep(200000 * $attempt);

                $freshLead = $amoApi->service->leads()->find($lead->id);

                if (!$freshLead) {
                    throw $e;
                }

                if ($reapply) {
                    $freshLead = $reapply($freshLead);
                }

                $lead = $freshLead;
            }
        }

        return $lead;
    }

    private function addTagWithRetry($lead, $tag, Client $amoApi)
    {
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return Tags::add($lead, $tag);
            } catch (Throwable $e) {
                if (!$this->isStaleUpdateError($e) || empty($lead?->id) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                $this->warn('Tilda form send: tag stale update conflict, retrying');
                usleep(200000 * $attempt);

                $freshLead = $amoApi->service->leads()->find($lead->id);

                if (!$freshLead) {
                    throw $e;
                }

                $lead = $freshLead;
            }
        }

        return $lead;
    }

    private function isStaleUpdateError(Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Last modified date is older than in database');
    }
}
