<?php

namespace App\Services\Admin;

use App\Models\User;

class UserRoleChangeAuditor
{
    /**
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public function record(
        User $subject,
        array $before,
        array $after,
        ?User $actor,
        string $source,
    ): void {
        sort($before);
        sort($after);

        if ($before === $after) {
            return;
        }

        $activity = activity('security')
            ->performedOn($subject)
            ->event('user_roles_changed')
            ->withProperties([
                'before' => $before,
                'after' => $after,
                'source' => $source,
            ]);

        if ($actor !== null) {
            $activity->causedBy($actor);
        }

        $activity->log('User roles changed');
    }
}
