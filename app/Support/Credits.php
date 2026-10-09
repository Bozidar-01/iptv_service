<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * What a subscription costs in credits: 1 credit per month. Counted in calendar months from the day the
 * user's current subscription runs out (or from today, when it already ended or never started) to the new
 * end date, rounded up. Making it shorter or keeping it costs nothing.
 *
 * One extra day is not charged for: the reseller's own date can differ from the server's by a day around midnight
 * (time zones), so "+1 month" must stay 1 credit. Any longer subscription costs at least 1 credit, so extending
 * a day at a time is not free. resources/views/admin/users/_form.blade.php has the same rule in JavaScript
 * for the live preview: change both together.
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

        // Months until one day before the end date (the tolerance), but never less than 1 for a longer subscription
        $limit = $to->copy()->subDay();
        $months = 0;
        while ($from->copy()->addMonthsNoOverflow($months)->lt($limit) && $months < 1200) {
            $months++;
        }

        return max(1, $months);
    }
}
