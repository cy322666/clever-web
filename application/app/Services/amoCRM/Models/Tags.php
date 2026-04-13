<?php


namespace App\Services\amoCRM\Models;

use Throwable;

class Tags
{
    public static function add($lead, $tagname)
    {
        $hasTag = false;

        if (is_array($tagname) && !empty($tagname)) {
            $lead->attachTags($tagname);
            $hasTag = true;
        }

        if (is_string($tagname) && trim($tagname) !== '') {
            $lead->attachTag($tagname);
            $hasTag = true;
        }

        if ($hasTag) {
            $maxAttempts = 5;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $lead->save();
                    break;
                } catch (Throwable $e) {
                    $isStale = str_contains($e->getMessage(), 'Last modified date is older than in database');

                    if (!$isStale || empty($lead?->id) || !method_exists(
                            $lead->service,
                            'find'
                        ) || $attempt >= $maxAttempts) {
                        throw $e;
                    }

                    usleep(200000 * $attempt);
                    $freshLead = $lead->service->find($lead->id);

                    if (!$freshLead) {
                        throw $e;
                    }

                    if (is_array($tagname) && !empty($tagname)) {
                        $freshLead->attachTags($tagname);
                    }

                    if (is_string($tagname) && trim($tagname) !== '') {
                        $freshLead->attachTag($tagname);
                    }

                    $lead = $freshLead;
                }
            }
        }

        return $lead;
    }
}
