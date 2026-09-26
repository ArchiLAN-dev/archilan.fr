<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Support\StaffAlertFactory;
use App\GameSelection\Application\Support\StaffAlertLevel;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\Sessions\Application\Support\GenerationFailureParser;
use PHPUnit\Framework\TestCase;

final class StaffAlertFactoryTest extends TestCase
{
    private const string HASH = '6d1ef721c1b08488d3f5a790984fbf5e6a956a1a7b2c3e66d280d1b1d744e136';
    private const string CRYSTAL_ERROR = <<<'ERR'
        Traceback (most recent call last):
          File "/usr/local/bin/generate_multiworld.py", line 468, in <module>
            ERmain(erargs, seed)
        Fill.FillError: Could not access required locations for accessibility check. Missing: [Castle Ramparts Chest - Jump down from western save point]
        ERR;

    private StaffAlertFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new StaffAlertFactory('https://archilan.fr');
    }

    public function testOpenedAlertCarriesGameTypeShortHashAndOneLineSummary(): void
    {
        $alert = $this->factory->opened($this->incident(self::CRYSTAL_ERROR), 'Crystal Project');

        self::assertSame('Apworld en échec : Crystal Project', $alert->title);
        self::assertSame(StaffAlertLevel::Alert, $alert->level);
        self::assertSame('https://archilan.fr/admin/sante-apworlds', $alert->url);
        self::assertStringContainsString('Test de génération en échec', $alert->description);
        self::assertStringContainsString('6d1ef721c1b0', $alert->description);
        self::assertStringNotContainsString(self::HASH, $alert->description, 'never the full hash');
        self::assertStringContainsString(GenerationFailureParser::summarize(self::CRYSTAL_ERROR), $alert->description);
        self::assertStringNotContainsString('Traceback', $alert->description, 'a one-line summary, not the dump');
    }

    public function testAcknowledgedAlertNamesTheAdmin(): void
    {
        $incident = $this->incident('boom');
        $incident->acknowledge('admin-1', new \DateTimeImmutable('2026-09-24 11:00:00+00:00'));

        $alert = $this->factory->acknowledged($incident, 'Crystal Project', 'Jean');

        self::assertSame("Jean s'occupe de Crystal Project", $alert->title);
        self::assertSame(StaffAlertLevel::Info, $alert->level);
        self::assertSame('https://archilan.fr/admin/sante-apworlds', $alert->url);
    }

    public function testResolvedAutomaticallySaysSoWithoutAnAdmin(): void
    {
        $incident = $this->incident('boom');
        $incident->resolve(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), null);

        $alert = $this->factory->closed($incident, 'Crystal Project', null);

        self::assertSame('Crystal Project : incident résolu automatiquement', $alert->title);
        self::assertSame(StaffAlertLevel::Resolved, $alert->level);
    }

    public function testResolvedByAnAdminNamesThem(): void
    {
        $incident = $this->incident('boom');
        $incident->resolve(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), 'admin-1');

        $alert = $this->factory->closed($incident, 'Crystal Project', 'Jean');

        self::assertSame('Crystal Project : incident résolu par Jean', $alert->title);
    }

    public function testIgnoredByAnAdminNamesThem(): void
    {
        $incident = $this->incident('boom');
        $incident->ignore(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), 'admin-1');

        $alert = $this->factory->closed($incident, 'Crystal Project', 'Jean');

        self::assertSame('Crystal Project : incident ignoré par Jean', $alert->title);
        self::assertSame(StaffAlertLevel::Resolved, $alert->level);
    }

    public function testIgnoredWithoutAnAdminSaysTheVerdictWasForced(): void
    {
        $incident = $this->incident('boom');
        $incident->ignore(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), null);

        $alert = $this->factory->closed($incident, 'Crystal Project', null);

        self::assertSame('Crystal Project : incident ignoré, verdict forcé par un admin', $alert->title);
    }

    public function testClosingAnActiveIncidentIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        $this->factory->closed($this->incident('boom'), 'Crystal Project', null);
    }

    public function testLongErrorAndGameNameAreTruncatedToDiscordLimits(): void
    {
        $alert = $this->factory->opened($this->incident(str_repeat('x', 10_000)), str_repeat('Long ', 100));

        self::assertLessThanOrEqual(StaffAlertFactory::TITLE_MAX, mb_strlen($alert->title));
        self::assertLessThanOrEqual(StaffAlertFactory::DESCRIPTION_MAX, mb_strlen($alert->description));
    }

    public function testTrailingSlashOfTheSiteUrlIsIgnored(): void
    {
        $alert = new StaffAlertFactory('https://archilan.fr/')->opened($this->incident('boom'), 'Crystal Project');

        self::assertSame('https://archilan.fr/admin/sante-apworlds', $alert->url);
    }

    public function testAnUpdateIncidentIsNamedForWhatItIs(): void
    {
        $rejected = ApworldIncident::open('i-1', 'game-1', self::HASH, ApworldIncidentType::UpdateRejected, 'boom', new \DateTimeImmutable());
        $ambiguous = ApworldIncident::open('i-2', 'game-1', 'release:v2.0.0', ApworldIncidentType::UpdateAmbiguous, 'two apworlds', new \DateTimeImmutable());

        self::assertStringContainsString('Mise à jour rejetée', $this->factory->opened($rejected, 'Crystal Project')->description);
        self::assertStringContainsString('Mise à jour à arbitrer', $this->factory->opened($ambiguous, 'Crystal Project')->description);
    }

    public function testAnAutomaticPromotionAnnouncesBothVersionsAndTheRelease(): void
    {
        // Story 38.6 AC 11: without a freeze window, the staff must hear about every automatic switch -
        // the client mod the players need may have changed with it.
        $alert = $this->factory->promoted(
            'Crystal Project',
            'game-1',
            'CrystalProject-v0.17.0',
            'CrystalProject-v0.18.2',
            ApworldCandidateOrigin::Auto,
            null,
            'https://github.com/Emerassi/CrystalProjectAPWorld/releases/tag/CrystalProject-v0.18.2',
        );

        self::assertSame('Crystal Project mis à jour : CrystalProject-v0.17.0 → CrystalProject-v0.18.2', $alert->title);
        self::assertStringContainsString('Mise à jour automatique', $alert->description);
        self::assertStringContainsString('mod client', $alert->description);
        self::assertSame('https://github.com/Emerassi/CrystalProjectAPWorld/releases/tag/CrystalProject-v0.18.2', $alert->url);
        self::assertSame(StaffAlertLevel::Info, $alert->level);
    }

    public function testAForcedPromotionNamesTheAdminAndFallsBackToTheGamePage(): void
    {
        $alert = $this->factory->promoted('Crystal Project', 'game-1', null, null, ApworldCandidateOrigin::Manual, 'Jean', null);

        self::assertSame('Crystal Project mis à jour : version inconnue → nouvelle version', $alert->title);
        self::assertStringContainsString('Forcée par Jean malgré le test', $alert->description);
        self::assertSame('https://archilan.fr/admin/jeux/game-1', $alert->url);
    }

    private function incident(string $error): ApworldIncident
    {
        return ApworldIncident::open(
            'incident-1',
            'game-1',
            self::HASH,
            ApworldIncidentType::PreflightFailed,
            $error,
            new \DateTimeImmutable('2026-09-24 10:00:00+00:00'),
        );
    }
}
