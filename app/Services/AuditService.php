<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The audit log: who changed what. Call record() right after the change succeeded. It never throws: a failure
 * to write the log must not undo or block the change itself.
 */
class AuditService
{
    /**
     * @param  string  $action  one of the AuditLog constants
     * @param  User|array{type: string, id: ?int, name: string}|null  $subject  the user it was about, or another thing by type/id/name
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, User|array|null $subject = null, array $details = [], ?User $actor = null): void
    {
        $actor ??= Auth::user();

        if ($subject instanceof User) {
            $subject = ['type' => 'user', 'id' => $subject->id, 'name' => $subject->username];
        }

        try {
            AuditLog::create([
                'actor_id' => $actor?->id,
                'actor_name' => $actor instanceof User ? $actor->username : 'system',
                'action' => $action,
                'subject_type' => $subject['type'] ?? null,
                'subject_id' => $subject['id'] ?? null,
                'subject_name' => isset($subject['name']) ? mb_substr($subject['name'], 0, 255) : null,
                'details' => $details ?: null,
                'ip' => request()->ip(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * What describes a user for the log: the fields an admin or reseller can change, and bouquet names.
     *
     * @return array<string, mixed>
     */
    public function snapshot(User $user): array
    {
        $user = $user->fresh(['bouquets', 'owner']) ?? $user;

        return [
            'username' => $user->username,
            'status' => $user->status,
            'email' => $user->email,
            'locale' => $user->locale,
            'expires' => $user->expiration_date?->format('Y-m-d H:i'),
            'streams' => $user->max_connections,
            'role' => $user->role,
            'reseller' => $user->owner?->username,
            'max_customers' => $user->max_customers,
            'max_customer_connections' => $user->max_customer_connections,
            'bouquets' => $user->bouquets->pluck('name')->sort()->values()->all(),
        ];
    }

    /**
     * The difference between two snapshots, as audit details: changed fields as [old, new] and bouquets added / removed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, mixed>
     */
    public function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $value) {
            if ($field !== 'bouquets' && ($before[$field] ?? null) != $value) {
                $changes[$field] = [$before[$field] ?? null, $value];
            }
        }

        return array_filter([
            'changes' => $changes,
            'bouquets_added' => array_values(array_diff($after['bouquets'] ?? [], $before['bouquets'] ?? [])),
            'bouquets_removed' => array_values(array_diff($before['bouquets'] ?? [], $after['bouquets'] ?? [])),
        ]);
    }
}
