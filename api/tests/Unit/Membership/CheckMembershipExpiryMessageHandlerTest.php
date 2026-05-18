<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\Handler\CheckMembershipExpiryMessageHandler;
use App\Membership\Application\Message\CheckMembershipExpiryMessage;
use App\Membership\Application\Message\ExpireMembershipMessage;
use App\Membership\Application\Message\MembershipReminderMessage;
use App\Membership\Domain\Membership;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class CheckMembershipExpiryMessageHandlerTest extends TestCase
{
    public function testInvokeDispatchesExpireMembershipMessagesForExpiredMemberships(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(2))->method('dispatch')->willReturn(new Envelope(new \stdClass()))
            ->with(self::callback(static fn (object $msg): bool => $msg instanceof ExpireMembershipMessage));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $handler = new CheckMembershipExpiryMessageHandler(
            $this->createConnectionReturning(['id-1', 'id-2'], [], []),
            $em,
            $bus,
        );

        $handler(new CheckMembershipExpiryMessage());
    }

    public function testInvokeWritesReminder30BeforeDispatch(): void
    {
        $membership = Membership::create(
            'user-1',
            new \DateTimeImmutable(),
            new \DateTimeImmutable('+365 days'),
            'helloasso',
            null,
            null,
            new \DateTimeImmutable(),
        );

        $callOrder = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($membership);
        $em->expects(self::once())->method('flush')->willReturnCallback(static function () use (&$callOrder): void {
            $callOrder[] = 'flush';
        });

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')
            ->with(new MembershipReminderMessage('id-30', 30))
            ->willReturnCallback(static function () use (&$callOrder): Envelope {
                $callOrder[] = 'dispatch';

                return new Envelope(new \stdClass());
            });

        $handler = new CheckMembershipExpiryMessageHandler(
            $this->createConnectionReturning([], ['id-30'], []),
            $em,
            $bus,
        );

        $handler(new CheckMembershipExpiryMessage());

        self::assertSame(['flush', 'dispatch'], $callOrder);
    }

    public function testInvokeWritesReminder7BeforeDispatch(): void
    {
        $membership = Membership::create(
            'user-1',
            new \DateTimeImmutable(),
            new \DateTimeImmutable('+7 days'),
            'helloasso',
            null,
            null,
            new \DateTimeImmutable(),
        );

        $callOrder = [];

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($membership);
        $em->expects(self::once())->method('flush')->willReturnCallback(static function () use (&$callOrder): void {
            $callOrder[] = 'flush';
        });

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')
            ->with(new MembershipReminderMessage('id-7', 7))
            ->willReturnCallback(static function () use (&$callOrder): Envelope {
                $callOrder[] = 'dispatch';

                return new Envelope(new \stdClass());
            });

        $handler = new CheckMembershipExpiryMessageHandler(
            $this->createConnectionReturning([], [], ['id-7']),
            $em,
            $bus,
        );

        $handler(new CheckMembershipExpiryMessage());

        self::assertSame(['flush', 'dispatch'], $callOrder);
    }

    public function testInvokeDoesNothingWhenNoMembershipsMatch(): void
    {
        $typedDateParams = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $handler = new CheckMembershipExpiryMessageHandler(
            $this->createConnectionReturning([], [], [], $typedDateParams),
            $em,
            $bus,
        );

        $handler(new CheckMembershipExpiryMessage());

        self::assertSame(['now', 'now', 'deadline', 'now', 'deadline'], $typedDateParams);
    }

    /**
     * @param list<mixed>  $expiredIds
     * @param list<mixed>  $reminder30Ids
     * @param list<mixed>  $reminder7Ids
     * @param list<string> $typedDateParams
     */
    private function createConnectionReturning(
        array $expiredIds,
        array $reminder30Ids,
        array $reminder7Ids,
        array &$typedDateParams = [],
    ): Connection {
        $result = $this->createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturnOnConsecutiveCalls(
            $expiredIds,
            $reminder30Ids,
            $reminder7Ids,
        );

        $expr = $this->createStub(ExpressionBuilder::class);

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('setParameter')->willReturnCallback(
            static function (string $key, mixed $value, mixed $type = null) use ($qb, &$typedDateParams): QueryBuilder {
                if (in_array($key, ['now', 'deadline'], true)) {
                    self::assertInstanceOf(\DateTimeImmutable::class, $value);
                    self::assertSame(Types::DATETIMETZ_IMMUTABLE, $type);
                    $typedDateParams[] = $key;
                }

                return $qb;
            },
        );
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($result);

        $connection = $this->createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($qb);

        return $connection;
    }
}
