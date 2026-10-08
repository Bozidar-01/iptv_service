<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * What a subscription costs in credits: 1 credit per month. Counted in calendar months from the day the
 * user's current subscription runs out (or from today, when it already ended or never started) to the new
 * end date, rounded up. Making it shorter or keeping it costs nothing.
 *
 * +1 month from the end date = 1 credit; one day more = 2. resources/views/admin/users/_form.blade.php
 * has the same rule in JavaScript for the live preview: change both together.
 */
class Credits
{
    public static function months(?Carbon $currentExpiry, Carbon $newExpiry, ?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $from = $currentExpiry && $currentExpiry->copy()->startOfDay()->gt($today) ? $currentExpiry->copy()->startOfDay() : $today;
        $to = $newExpiry->copy()->startOfDay();

        if ($to->lte($from)) {
            return 0;
        }

        $months = 0;
        while ($from->copy()->addMonthsNoOverflow($months)->lt($to) && $months < 1200) {
            $months++;
        }

        return $months;
    }
}
