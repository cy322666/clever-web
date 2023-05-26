<?php

namespace App\Jobs;

use App\Models\Core\Account;
use App\Models\Integrations\GetCourse\Order;
use App\Models\Integrations\GetCourse\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GetCoursePaySend implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function uniqueId(): string
    {
        return $this->setting->id;
    }

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        private Order $order,
        private Setting $setting,
        private Account $account,
    ) {
        $this->onQueue('getcourse_order_export');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        //
    }
}
