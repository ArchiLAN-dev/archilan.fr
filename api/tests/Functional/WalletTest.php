<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\AdminUserActionAudit;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/**
 * Story 41.1: the pelles ledger, the member's wallet and the admin credit/debit.
 */
final class WalletTest extends FunctionalTestCase
{
    public function testAnAdminCreditsAMemberWithGoldPelles(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/pelles', $target->getId()), [
            'direction' => 'credit',
            'amount' => 50,
            'kind' => 'gold',
            'reason' => 'Aide au montage de la LAN',
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $this->jsonBody();
        self::assertSame(0, $body['balanceBefore']);
        self::assertSame(50, $body['balanceAfter']);

        $movement = $this->singleMovement();
        self::assertSame(50, $movement->getAmount());
        self::assertSame(PelleReason::AdminCredit, $movement->getReason());
        self::assertSame('Aide au montage de la LAN', $movement->getLabel());
        self::assertSame($admin->getId(), $movement->getAuthorId());

        $audits = $this->entityManager->getRepository(AdminUserActionAudit::class)->findAll();
        self::assertCount(1, $audits);
        self::assertSame(AdminUserActionAudit::ACTION_PELLES_CREDIT, $audits[0]->getAction());

        $notifications = $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $target->getId()]);
        self::assertCount(1, $notifications);
        self::assertSame(Notification::TYPE_PELLES_ADJUSTED, $notifications[0]->getType());
    }

    public function testADebitBelowZeroIsRefusedWithTheAvailableBalance(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->credit($target->getId(), 30);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/pelles', $target->getId()), [
            'direction' => 'debit',
            'amount' => 31,
            'kind' => 'gold',
            'reason' => 'Correction',
        ]);

        self::assertResponseStatusCodeSame(422);
        $body = $this->jsonBody();
        $error = $this->section($body, 'error');
        self::assertSame('insufficient_pelles', $error['code'] ?? null);
        self::assertSame(['available' => 30], $error['details'] ?? null);
        self::assertCount(1, $this->entityManager->getRepository(PelleMovement::class)->findAll());
    }

    public function testADebitWithinTheBalanceIsAnInverseMovement(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->credit($target->getId(), 30);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/pelles', $target->getId()), [
            'direction' => 'debit',
            'amount' => 30,
            'kind' => 'gold',
            'reason' => 'Crédit en double',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(0, $this->jsonBody()['balanceAfter']);
        self::assertCount(2, $this->entityManager->getRepository(PelleMovement::class)->findAll());
    }

    public function testAnAdminCannotCreditThemselves(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/pelles', $admin->getId()), [
            'direction' => 'credit',
            'amount' => 50,
            'kind' => 'gold',
            'reason' => 'Pour moi',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager->getRepository(PelleMovement::class)->findAll());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidAdjustments(): iterable
    {
        yield 'zero' => [['direction' => 'credit', 'amount' => 0, 'kind' => 'gold', 'reason' => 'x']];
        yield 'too much' => [['direction' => 'credit', 'amount' => 10001, 'kind' => 'gold', 'reason' => 'x']];
        yield 'no reason' => [['direction' => 'credit', 'amount' => 10, 'kind' => 'gold', 'reason' => '  ']];
        yield 'unknown direction' => [['direction' => 'steal', 'amount' => 10, 'kind' => 'gold', 'reason' => 'x']];
        yield 'event pelles without event' => [['direction' => 'credit', 'amount' => 10, 'kind' => 'event', 'reason' => 'x']];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAdjustments')]
    public function testAnInvalidAdjustmentIsRefused(array $payload): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/pelles', $target->getId()), $payload);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager->getRepository(PelleMovement::class)->findAll());
    }

    public function testANonAdminCannotAdjust(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'Lambda');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/pelles', $target->getId()), [
            'direction' => 'credit', 'amount' => 10, 'kind' => 'gold', 'reason' => 'x',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownMemberIsNotFound(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/users/nonexistentid000000000000000000/pelles', [
            'direction' => 'credit', 'amount' => 10, 'kind' => 'gold', 'reason' => 'x',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testEventPellesAreKeptPerEvent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $event = $this->createEvent('ArchiLAN #3', new \DateTimeImmutable('2026-11-01T10:00:00+00:00'), new \DateTimeImmutable('2026-11-02T18:00:00+00:00'), published: true);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/pelles', $target->getId()), [
            'direction' => 'credit', 'amount' => 15, 'kind' => 'event', 'eventId' => $event->getId(), 'reason' => 'Happening',
        ]);
        self::assertResponseStatusCodeSame(201);

        $this->loginAs($target);
        $this->client->request('GET', '/api/v1/me/wallet');
        self::assertResponseIsSuccessful();
        $wallet = $this->jsonBody();
        self::assertSame(0, $wallet['gold']);
        self::assertSame([['eventId' => $event->getId(), 'eventTitle' => 'ArchiLAN #3', 'balance' => 15]], $wallet['events']);
    }

    public function testTheWalletShowsTheBalanceAndAPagedHistory(): void
    {
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        for ($i = 1; $i <= 27; ++$i) {
            $this->credit($member->getId(), $i);
        }
        $this->loginAs($member);

        $this->client->request('GET', '/api/v1/me/wallet');
        self::assertResponseIsSuccessful();
        $wallet = $this->jsonBody();
        self::assertSame(378, $wallet['gold']);
        $history = $this->section($wallet, 'history');
        self::assertSame(27, $history['total'] ?? null);
        self::assertCount(25, $this->section($history, 'items'));

        $this->client->request('GET', '/api/v1/me/wallet?page=2');
        self::assertCount(2, $this->section($this->section($this->jsonBody(), 'history'), 'items'));
    }

    public function testTheWalletIsPrivate(): void
    {
        $this->client->request('GET', '/api/v1/me/wallet');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAKeyedMovementIsRecordedOnce(): void
    {
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $record = $this->recorder();
        $input = new RecordPelleMovementInput($member->getId(), 20, PelleKind::Gold, null, PelleReason::AdminCredit, 'Happening', null, 'happening-1-'.$member->getId());

        $first = $record->record($input);
        $second = $record->record($input);

        self::assertFalse($first->alreadyRecorded);
        self::assertTrue($second->alreadyRecorded);
        self::assertSame($first->movementId, $second->movementId);
        self::assertSame(20, $second->balanceAfter);
        self::assertCount(1, $this->entityManager->getRepository(PelleMovement::class)->findAll());
    }

    public function testABannedMemberEarnsNothingExceptFromAnAdmin(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $member->ban('Triche', new \DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        $this->entityManager->flush();

        $refused = false;
        try {
            $this->recorder()->record(new RecordPelleMovementInput($member->getId(), 20, PelleKind::Gold, null, PelleReason::AdminCredit, 'Happening', null, null));
        } catch (\App\Shared\Application\Exception\ForbiddenException) {
            $refused = true;
        }
        self::assertTrue($refused);

        $this->loginAs($admin);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/users/%s/pelles', $member->getId()), [
            'direction' => 'credit', 'amount' => 10, 'kind' => 'gold', 'reason' => 'Geste',
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testADeletedAccountKeepsItsLedgerButMovesNoMore(): void
    {
        // The circulation totals must stay right after an erasure: the lines stay, tied to the anonymised
        // member, and nothing is written for them afterwards.
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->credit($member->getId(), 25);
        $delete = self::getContainer()->get(\App\Identity\Application\Command\DeleteAccount::class);
        self::assertInstanceOf(\App\Identity\Application\Command\DeleteAccount::class, $delete);
        $delete->delete($member);

        self::assertCount(1, $this->entityManager->getRepository(PelleMovement::class)->findBy(['userId' => $member->getId()]));
        $this->expectException(\App\Shared\Application\Exception\NotFoundException::class);
        $this->credit($member->getId(), 5);
    }

    public function testAnAdminReadsAMembersWalletForTheConfirmation(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->credit($target->getId(), 40);
        $this->loginAs($admin);

        $this->client->request('GET', sprintf('/api/v1/admin/users/%s/pelles', $target->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame(40, $this->jsonBody()['gold']);
    }

    public function testTheCirculationSumsCreationsAndDestructionsOverThePeriod(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->credit($member->getId(), 100);
        $this->recorder()->record(new RecordPelleMovementInput($member->getId(), -40, PelleKind::Gold, null, PelleReason::AdminDebit, 'Correction', $admin->getId(), null, true));
        // A credit from six weeks ago: before a 4-week period, inside the period before it.
        $this->credit($member->getId(), 7);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE pelle_movement SET created_at = created_at - INTERVAL '6 weeks' WHERE amount = 7",
        );
        $this->loginAs($admin);

        $this->client->request('GET', '/api/v1/admin/statistiques/pelles?period=4s');

        self::assertResponseIsSuccessful();
        $body = $this->jsonBody();
        self::assertSame(67, $body['goldInCirculation'], 'what exists now, whatever the period');
        $created = $this->section($body, 'created');
        self::assertSame(100, $created['total'] ?? null);
        self::assertSame(7, $created['previous'] ?? null);
        self::assertCount(4, $this->section($created, 'series'));
        self::assertSame(40, $this->section($body, 'destroyed')['total'] ?? null);
        $byReason = $this->section($body, 'byReason');
        self::assertSame(['reason' => 'admin_credit', 'created' => 100, 'destroyed' => 0], $byReason[0] ?? null);
        self::assertSame(['reason' => 'admin_debit', 'created' => 0, 'destroyed' => 40], $byReason[1] ?? null);
        self::assertSame('4s', $this->section($body, 'period')['code'] ?? null);
    }

    public function testTheCirculationDashboardIsAdminOnly(): void
    {
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->loginAs($member);

        $this->client->request('GET', '/api/v1/admin/statistiques/pelles');

        self::assertResponseStatusCodeSame(403);
    }

    private function credit(string $userId, int $amount): void
    {
        $this->recorder()->record(new RecordPelleMovementInput($userId, $amount, PelleKind::Gold, null, PelleReason::AdminCredit, 'Crédit de test', null, null));
    }

    private function recorder(): RecordPelleMovement
    {
        $record = self::getContainer()->get(RecordPelleMovement::class);
        self::assertInstanceOf(RecordPelleMovement::class, $record);

        return $record;
    }

    private function singleMovement(): PelleMovement
    {
        $movements = $this->entityManager->getRepository(PelleMovement::class)->findAll();
        self::assertCount(1, $movements);

        return $movements[0];
    }

    /**
     * @return array<mixed>
     */
    private function jsonBody(): array
    {
        return $this->decodedJsonResponse();
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<mixed>
     */
    private function section(array $body, string|int $key): array
    {
        $section = $body[$key] ?? null;
        self::assertIsArray($section);

        return $section;
    }
}
