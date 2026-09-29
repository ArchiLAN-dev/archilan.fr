<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

use App\Community\Application\Support\WebPushMessage;
use App\Community\Domain\Entity\PushSubscription;

/**
 * Sends one browser push to one device (story 40.2): encrypted for its keys, signed with the site's
 * VAPID keys, posted to its push service.
 */
interface WebPushSender
{
    public function send(PushSubscription $subscription, WebPushMessage $message): WebPushOutcome;
}
