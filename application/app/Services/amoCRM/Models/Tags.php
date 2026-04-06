<?php


namespace App\Services\amoCRM\Models;


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
            $lead->save();
        }

        return $lead;
    }
}
