<?php

namespace App\Support;

class Organizations
{
    public static function types()
    {
        return [
            'nonprofit' => __('labels.nonprofit'),
            'business' => __('labels.business'),
            'none' => __('labels.none_org_type'),
        ];
    }
}
