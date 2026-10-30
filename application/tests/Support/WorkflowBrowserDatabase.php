<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class WorkflowBrowserDatabase
{
    public static function prepare(string $database = ':memory:'): void
    {
        config(['cache.default' => 'array', 'session.driver' => 'array']);
        app()->instance(\Illuminate\Cache\RateLimiter::class, new \Illuminate\Cache\RateLimiter(\Illuminate\Support\Facades\Cache::store('array')));
        WorkflowListDatabase::prepare($database);
        if (Schema::hasTable('amocrm_fields')) {
            return;
        }
        Schema::create('amocrm_fields', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('entity_type');
            $table->integer('field_id');
            $table->string('name');
            $table->string('type')->default('text');
            $table->string('code')->nullable();
            $table->text('enums')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('active')->default(true);
        });
    }
}
