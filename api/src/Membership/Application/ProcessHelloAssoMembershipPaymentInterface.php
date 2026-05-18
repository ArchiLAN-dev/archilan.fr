<?php

declare(strict_types=1);

namespace App\Membership\Application;

interface ProcessHelloAssoMembershipPaymentInterface
{
    public function process(string $helloassoOrderId, ?string $payerEmail, ?\DateTimeImmutable $paidAt): void;
}
