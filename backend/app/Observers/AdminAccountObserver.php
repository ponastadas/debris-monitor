<?php

namespace App\Observers;

use App\Models\AdminAccount;

class AdminAccountObserver
{
    /**
     * Immediately revoke all tokens when an admin account is deactivated.
     *
     * This fires before the UPDATE is committed, but tokens are deleted
     * in the same request so subsequent API calls with those tokens will
     * return 401 immediately — no delay, no polling window.
     */
    public function updating(AdminAccount $admin): void
    {
        if ($admin->isDirty('is_active') && ! $admin->is_active) {
            $admin->tokens()->delete();
        }
    }
}
