<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Daily housekeeping for subscribers: trials are deleted a while after they ended, and subscribers whose subscription
 * ended long ago are switched off. Both are written to the audit log (no actor = the system). A renewal turns a
 * switched-off user back on, so nobody has to remember to enable them.
 */
class UserCleanupService
{
    protected $audit;

    public function __construct(AuditService $audit)
    {
        $this->audit = $audit;
    }

    /**
     * @return array{deleted: int, disabled: int}
     */
    public function run(): array
    {
        return ['deleted' => $this->deleteOldTrials(), 'disabled' => $this->disableLongExpired()];
    }

    /** Trials that ended more than TRIAL_KEEP_DAYS ago are deleted (0 = never). */
    private function deleteOldTrials(): int
    {
        $days = (int) config('iptv.trial_keep_days');
        if ($days < 1) {
            return 0;
        }

        $old = User::where('role', 'user')->where('is_trial', true)->where('expiration_date', '<', now()->subDays($days));
        $names = (clone $old)->orderBy('id')->limit(20)->pluck('username')->all();
        $count = $old->delete();

        if ($count > 0) {
            $this->audit->record(AuditLog::USERS_AUTO_DELETED, null, ['count' => $count, 'sample' => $names]);
        }

        return $count;
    }

    /** Active subscribers whose subscription ended more than EXPIRED_DISABLE_DAYS ago are switched off (0 = never). */
    private function disableLongExpired(): int
    {
        $days = (int) config('iptv.expired_disable_days');
        if ($days < 1) {
            return 0;
        }

        $old = User::where('role', 'user')->where('status', 'active')->where('expiration_date', '<', now()->subDays($days));
        $names = (clone $old)->orderBy('id')->limit(20)->pluck('username')->all();
        $count = $old->update(['status' => 'disabled']);

        if ($count > 0) {
            $this->audit->record(AuditLog::USERS_AUTO_DISABLED, null, ['count' => $count, 'sample' => $names]);
        }

        return $count;
    }
}
