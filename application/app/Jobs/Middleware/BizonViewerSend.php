<?php

namespace App\Jobs\Middleware;

use Illuminate\Contracts\Redis\LimiterTimeoutException;

class BizonViewerSend
{
    private int $seconds = 3;
    /**
     * Обработать задание в очереди. Ограничивает частоту выполнения jobs
     *
     * @param mixed $job
     * @param callable $next
     * @return void
     * @throws LimiterTimeoutException
     */
    public function handle(mixed $job, callable $next)
    {
//        Redis::throttle('key')
//            ->block(0)
//            ->allow(1)
//            ->every($this->seconds)
//            ->then(function () use ($job, $next) {
//
//                $next($job);
//            }, function () use ($job) {
//
//                $job->release($this->seconds);
//            });
    }
}
