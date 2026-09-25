<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Exception;

/**
 * The staff channel could not take an alert (story 38.2). Never fatal: an alert is a side effect of
 * an incident transition that is already committed.
 */
final class StaffAlertDeliveryException extends \RuntimeException
{
}
