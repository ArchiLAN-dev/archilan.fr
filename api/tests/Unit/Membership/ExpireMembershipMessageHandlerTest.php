<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\ExpireMembershipInterface;
use App\Membership\Application\Handler\ExpireMembershipMessageHandler;
use App\Membership\Application\Message\ExpireMembershipMessage;
use PHPUnit\Framework\TestCase;

final class ExpireMembershipMessageHandlerTest extends TestCase
{
    public function testInvokeCallsExpireWithMessageMembershipId(): void
    {
        $expireMembership = $this->createMock(ExpireMembershipInterface::class);
        $expireMembership->expects(self::once())->method('expire')->with('membership-abc');

        $handler = new ExpireMembershipMessageHandler($expireMembership);
        $handler(new ExpireMembershipMessage('membership-abc'));
    }
}
