<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Enum;

/** Story 41.1: why pelles moved. Later stories add the earning and spending reasons. */
enum PelleReason: string
{
    case AdminCredit = 'admin_credit';
    case AdminDebit = 'admin_debit';
}
