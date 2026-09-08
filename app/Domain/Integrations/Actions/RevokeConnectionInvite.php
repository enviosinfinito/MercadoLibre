<?php

namespace App\Domain\Integrations\Actions;

use App\Models\ConnectionInvite;

final class RevokeConnectionInvite
{
    public function execute(ConnectionInvite $invite): ConnectionInvite
    {
        if ($invite->revoked_at === null) {
            $invite->forceFill(['revoked_at' => now()])->save();
        }

        return $invite->refresh();
    }
}
