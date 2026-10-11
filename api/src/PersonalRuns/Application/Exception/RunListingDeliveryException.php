<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Exception;

/** Story 43.27: Discord refused or could not take a run listing message. The message never carries a credential. */
final class RunListingDeliveryException extends \RuntimeException
{
}
