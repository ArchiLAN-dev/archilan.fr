<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Enum;

/** Story 41.1: why pelles moved. Later stories add the earning and spending reasons. */
enum PelleReason: string
{
    case AdminCredit = 'admin_credit';
    case AdminDebit = 'admin_debit';
    // Story 41.2: event pelles handed out during an event, then converted and destroyed at its end.
    case EventDistribution = 'event_distribution';
    case EventConversion = 'event_conversion';
    case EventExpired = 'event_expired';
    // Story 41.3: a hint bought with pelles, and its refund when the hint could not be given.
    case HintPurchase = 'hint_purchase';
    case HintRefund = 'hint_refund';
}
