<?php

namespace App\Services\AlfaCRM\Models;

use App\Models\Integrations\Alfa\LeadSource;
use App\Models\Integrations\Alfa\LeadStatus;
use App\Services\AlfaCRM\Client;
use App\Services\AlfaCRM\Integrations\Models\Status;
use Illuminate\Support\Facades\Auth;

class Account
{
    public static function branches($alfaApi)
    {
        $branches = (new Branch($alfaApi))->all();

        foreach ($branches as $branch) {

            \App\Models\Integrations\Alfa\Branch::query()->create([
                'branch_id' => $branch->id,
                'name'      => $branch->name,
                'is_active' => $branch->is_active,
                'weight'    => $branch->weight,
                'subject_ids' => $branch->subject_ids,
                'user_id'     => Auth::id(),
            ]);
        }
    }

    public static function sources($alfaApi)
    {
        $sources = (new Source($alfaApi))->all();

        foreach ($sources as $source) {

            LeadSource::query()->create([
                'user_id' => Auth::id(),
                'code' => $source->code,
                'name' => $source->name,
                'is_enabled' => $source->is_enabled,
                'source_id'  => $source->source_id,
            ]);
        }
    }

    public static function statuses($alfaApi)
    {
        $statuses = (new Status($alfaApi))->all();

        foreach ($statuses as $status) {

            LeadStatus::query()->create([
                'user_id'    => Auth::id(),
                'is_enabled' => $status->is_enabled,
                'status_id'  => $status->status_id,
                'name'       => $status->name,
            ]);
        }
    }
}
