<?php

namespace App\Services\AlfaCRM\Models;

use App\Models\Integrations\Alfa\LeadSource;
use App\Models\Integrations\Alfa\LeadStatus;
use App\Services\AlfaCRM\Client;
use App\Services\AlfaCRM\Models\Status;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Account
{
    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function branches($alfaApi): void
    {
        $branches = (new Branch($alfaApi))->all();

        foreach ($branches as $branch) {

            \App\Models\Integrations\Alfa\Branch::query()
                ->updateOrCreate([
                    'branch_id' => $branch->id,
                    'user_id'     => Auth::id(),
                ], [
                    'name'      => $branch->name,
                    'is_active' => $branch->is_active,
                    'weight'    => $branch->weight,
                    'subject_ids' => json_encode($branch->subject_ids),
                ]);
        }
    }

    public static function sources($alfaApi): void
    {
        $sources = (new Source($alfaApi))->all();

        foreach ($sources as $source) {

            LeadSource::query()->updateOrCreate([
                'user_id' => Auth::id(),
                'code' => $source->code,
            ], [
                'name' => $source->name,
                'is_enabled' => $source->is_enabled,
                'source_id'  => $source->id,
            ]);
        }
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function statuses($alfaApi): void
    {
        $statuses = (new Status($alfaApi))->all();

        foreach ($statuses as $status) {

            LeadStatus::query()->updateOrCreate([
                'user_id'    => Auth::id(),
                'status_id'  => $status->id,
            ], [
                'is_enabled' => $status->is_enabled,
                'name'       => $status->name,
            ]);
        }
    }
}
