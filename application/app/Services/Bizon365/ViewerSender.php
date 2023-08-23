<?php

namespace App\Services\Bizon365;

use App\Models\amoCRM\Staff;
use App\Models\amoCRM\Status;
use App\Models\Integrations\Bizon\Setting;
use App\Models\Integrations\Bizon\Viewer;
use App\Models\Integrations\Bizon\ViewerNote;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Contacts;
use App\Services\amoCRM\Models\Leads;
use App\Services\amoCRM\Models\Notes;
use App\Services\amoCRM\Models\Tags;

abstract class ViewerSender
{
    /**
     * @param Client $amoApi
     * @param Viewer $viewer
     * @param Setting $setting
     * @return string
     */
    public static function send(
        Client $amoApi,
        Viewer $viewer,
        Setting $setting) :string
    {
            $pipelineId = Status::query()
                ->find($setting->pipeline_id)
                ->pipeline_id;

            $statusId   = Status::query()
                ->find($setting->{"status_id_$viewer->type"})
                ->status_id;

            $responsibleId = Staff::query()
                ->find($setting->response_user_id)
                ->staff_id;

            $contact = Contacts::search([
                'Телефоны' => [$viewer->phone],
                'Почта'    => $viewer->email
            ], $amoApi);

            if ($contact == null) {

                $contact = Contacts::create($amoApi, $viewer->username);
                $contact = Contacts::update($contact, [
                    'Телефоны' => [$viewer->phone],
                    'Почта'    => $viewer->email,
                    'Ответственный' => $responsibleId,
                ]);
            } else
                $lead = Leads::search($contact, $amoApi, $pipelineId);

            if (empty($lead)) {

                $lead = Leads::create($contact, [
                    'responsible_user_id' => $responsibleId,
                    'status_id'           => $statusId,
                ], 'Новый зритель вебинара');

                $lead = Leads::setUtms($lead, [
                    'utm_source'  => $viewer->utm_source ?? null,
                    'utm_medium'  => $viewer->utm_medium ?? null,
                    'utm_content' => $viewer->utm_content ?? null,
                    'utm_term'    => $viewer->utm_term ?? null,
                    'utm_campaign' => $viewer->utm_campaign ?? null,
                    'utm_referrer' => $viewer->utm_referrer ?? null,
                ]);
            }

            Notes::addOne($lead, ViewerNote::create($viewer));

            if ($viewer->commentaries)
                Notes::addOne($lead, ViewerNote::comments($viewer));

            Tags::add($lead, [
                $setting->tag,
                $setting->{"tag_$viewer->type"},
            ]);

            $viewer->lead_id    = $lead->id;
            $viewer->contact_id = $contact->id;
            $viewer->status     = Viewer::STATUS_OK;
            $viewer->save();

            return 1;
    }
}
