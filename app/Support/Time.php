<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class Time
{
    public static function duration($date_begin, $date_end)
    {
        $start = Carbon::parse($date_begin);
        $end = Carbon::parse($date_end);
        $days = (int) $start->diffInDays($end);
        $hours = (int) $start->diffInHours($end);
        $minutes = (int) $start->diffInMinutes($end);
        $seconds = (int) $start->diffInSeconds($end);

        if ($minutes > 60 * 24) {
            return $days.' days';
        }
        if ($minutes > 60) {
            return $hours.' hours';
        } else {
            return $minutes.' mins';
        }
    }
}
