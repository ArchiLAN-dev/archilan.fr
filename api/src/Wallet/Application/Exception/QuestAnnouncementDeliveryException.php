<?php

declare(strict_types=1);

namespace App\Wallet\Application\Exception;

/** Story 41.26: Discord refused or could not take the announcement. The message never carries a credential. */
final class QuestAnnouncementDeliveryException extends \RuntimeException
{
}
