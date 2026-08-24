<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\Community\Application\Query\CommunityLevelQuery;
use App\Community\Application\Query\CommunityPresenceQueryInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Domain\Repository\AchievementGrantRepositoryInterface;
use App\Identity\Application\Query\PlayerStatsQueryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;
use App\PersonalRuns\Application\Port\RunGameAssignmentInterface;
use App\PersonalRuns\Application\Service\PersonalRunDrafts;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Lecture d'une run privée. Le backoffice liste les runs d'un membre sur sa fiche et les lie
 * (story 36.4), sait déjà en arrêter une (36.6) et peut en télécharger le spoiler (16.8) - mais la
 * lecture refusait l'admin, si bien que le lien de sa propre interface répondait « Run introuvable ».
 *
 * L'admin lit sans devenir propriétaire : `isOwner` reste faux, donc le lien d'invitation, le mot de
 * passe admin de session et l'extrait de log de génération restent fermés.
 */
final class PersonalRunDraftsGetTest extends TestCase
{
    private const string OWNER_ID = 'owner-00000000000000000000000001';
    private const string OUTSIDER_ID = 'other-00000000000000000000000001';

    public function testOwnerReadsTheirRun(): void
    {
        $run = $this->aRun();
        $result = $this->drafts($run)->get($run->getId(), self::OWNER_ID, false);

        self::assertTrue($result['found']);
        self::assertTrue($result['authorized']);
        self::assertIsArray($result['payload']);
        self::assertTrue($result['payload']['isOwner']);
    }

    public function testAdminReadsAnotherMembersRun(): void
    {
        $run = $this->aRun();
        $result = $this->drafts($run)->get($run->getId(), self::OUTSIDER_ID, true);

        self::assertTrue($result['found']);
        self::assertTrue($result['authorized']);
        self::assertIsArray($result['payload']);
    }

    public function testAdminDoesNotBecomeTheOwner(): void
    {
        $run = $this->aRun();
        $payload = $this->drafts($run)->get($run->getId(), self::OUTSIDER_ID, true)['payload'];

        self::assertIsArray($payload);
        // `isOwner` garde une dizaine d'éléments côté front - réglages, override, renommage,
        // overlay, spoiler, lien d'invitation. Le passer à vrai pour un admin les ouvrirait tous.
        self::assertFalse($payload['isOwner']);
        self::assertNull($payload['inviteToken']);
        self::assertNull($payload['adminPassword']);
    }

    public function testOutsiderIsStillRefused(): void
    {
        $run = $this->aRun();
        $result = $this->drafts($run)->get($run->getId(), self::OUTSIDER_ID, false);

        self::assertTrue($result['found']);
        self::assertFalse($result['authorized']);
        self::assertNull($result['payload']);
    }

    public function testUnknownRunIsNotFoundEvenForAnAdmin(): void
    {
        $result = $this->drafts(null)->get('run-inconnue', self::OUTSIDER_ID, true);

        self::assertFalse($result['found']);
        self::assertFalse($result['authorized']);
        self::assertNull($result['payload']);
    }

    private function aRun(): Run
    {
        return Run::create(self::OWNER_ID, 'Partie Luigi\'s Mansion', new \DateTimeImmutable('2026-08-14T10:00:00+00:00'));
    }

    private function drafts(?Run $run): PersonalRunDrafts
    {
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        return new PersonalRunDrafts(
            $runs,
            self::createStub(RunParticipantRepositoryInterface::class),
            self::createStub(UserRepositoryInterface::class),
            self::createStub(SessionRepositoryInterface::class),
            self::createStub(CommunityUserDirectoryQueryInterface::class),
            self::createStub(ActiveMembershipQueryInterface::class),
            new CommunityLevelQuery(
                self::createStub(PlayerStatsQueryInterface::class),
                self::createStub(AchievementGrantRepositoryInterface::class),
            ),
            self::createStub(CommunityPresenceQueryInterface::class),
            self::createStub(RunGameAssignmentInterface::class),
            new MockClock(),
            'https://archilan.test',
        );
    }
}
