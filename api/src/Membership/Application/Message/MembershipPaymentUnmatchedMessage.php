<?php

declare(strict_types=1);

namespace App\Membership\Application\Message;

final readonly class MembershipPaymentUnmatchedMessage
{
    public function __construct(
        public string $payerEmail,
        public ?string $payerFirstName,
        public string $helloassoOrderId,
    ) {
    }
}
