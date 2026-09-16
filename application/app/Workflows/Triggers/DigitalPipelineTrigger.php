<?php

namespace App\Workflows\Triggers;

/** Only the dedicated amoCRM Digital Pipeline callback dispatches this start. */
class DigitalPipelineTrigger extends ManualTrigger
{
    public static function type(): string { return 'digital-pipeline'; }
    public static function name(): string { return 'Digital Pipeline'; }
    public static function description(): string { return 'Запуск из автоматизации воронки amoCRM. Выберите этот поток в действии виджета на этапе.'; }
    public static function icon(): string { return 'amocrm-digital-pipeline'; }
    public function shouldTrigger(array $config, mixed $subject, array $context = []): bool { return false; }
}
