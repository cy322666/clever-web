<?php

namespace App\Console\Commands\Install;

use App\Filament\Resources\Integrations\CallTranscriptionResource;
use App\Models\App;
use App\Models\Integrations\CallTranscription\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

class CallTranscriptionCreate extends Command
{
    private string $app = 'call-transcription';

    private string $resource = CallTranscriptionResource::class;

    protected $signature = 'install:call-transcription {user_id?}';

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
        $userId = $this->argument('user_id');

        if ($userId) {
            $user = User::query()
                ->with('account')
                ->find($userId);

            if (!$user?->account) {
                return;
            }

            $attributes = [
                'user_id' => $userId,
            ];

            if (Schema::hasColumn('call_transcription_settings', 'account_id')) {
                $attributes['account_id'] = $user->account->id;
            }

            if (!App::query()
                ->where('user_id', $userId)
                ->where('name', $this->app)
                ->exists()) {
                $setting = Setting::query()->create($attributes);

                App::query()->create([
                    'name' => $this->app,
                    'user_id' => $userId,
                    'setting_id' => $setting->id,
                    'resource_name' => $this->resource,
                ]);
            } elseif (!Setting::query()
                ->where('user_id', $userId)
                ->exists()) {
                Setting::query()->create($attributes);
            }
        } else {
            $users = User::query()->get();

            foreach ($users as $user) {
                Artisan::call('install:call-transcription', ['user_id' => $user->id]);
            }
        }
    }
}
