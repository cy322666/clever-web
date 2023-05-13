<?php


namespace App\Services\amoCRM\Models;


class Tags
{
    public static function add($lead, $tagname)
    {
        if(is_array($tagname))
            $lead->attachTags($tagname);

        if(is_string($tagname))
            $lead->attachTag($tagname);

        $lead->save();
    }
}
