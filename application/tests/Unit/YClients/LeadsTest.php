<?php

namespace Tests\Unit\YClients;

use App\Services\YClients\Leads;
use PHPUnit\Framework\TestCase;

class LeadsTest extends TestCase
{
    public function test_is_lead_allowed_for_sync_when_pipelines_are_not_configured(): void
    {
        $lead = $this->makeLead(statusId: 1, pipelineId: 77);

        $this->assertTrue(Leads::isLeadAllowedForSync($lead, null));
    }

    private function makeLead(int $statusId, int $pipelineId): object
    {
        return (object)[
            'status_id' => $statusId,
            'pipeline_id' => $pipelineId,
        ];
    }

    public function test_is_lead_rejected_for_closed_statuses(): void
    {
        $wonLead = $this->makeLead(statusId: 142, pipelineId: 77);
        $lostLead = $this->makeLead(statusId: 143, pipelineId: 77);

        $this->assertFalse(Leads::isLeadAllowedForSync($wonLead, null));
        $this->assertFalse(Leads::isLeadAllowedForSync($lostLead, null));
    }

    public function test_is_lead_filtered_by_pipelines_list(): void
    {
        $lead = $this->makeLead(statusId: 1, pipelineId: 100);

        $this->assertTrue(Leads::isLeadAllowedForSync($lead, [99, 100, 101]));
        $this->assertFalse(Leads::isLeadAllowedForSync($lead, [1, 2, 3]));
    }
}
