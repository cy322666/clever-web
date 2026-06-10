<?php

namespace App\Workflows;

class FailureStrategies
{
    public const STOP = 'stop';
    public const CONTINUE = 'continue';
    public const TELEGRAM_REPORT = 'telegram_report';
}
