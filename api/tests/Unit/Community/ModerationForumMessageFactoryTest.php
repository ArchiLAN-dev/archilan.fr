<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Entity\ModerationAction;
use PHPUnit\Framework\TestCase;

/**
 * Story 39.1: what the staff forum shows for each sanction.
 */
final class ModerationForumMessageFactoryTest extends TestCase
{
    private const string SITE = 'https://archilan.fr';

    public function testABanNamesTheMemberTheirDiscordAccountTheModeratorAndTheReason(): void
    {
        $action = ModerationAction::create('admin-1', 'user-1', ModerationAction::ACTION_BAN, 'Triche répétée', new \DateTimeImmutable('2026-09-27 10:00:00'), 'report-9');

        $message = new ModerationForumMessageFactory(self::SITE)->forAction($action, 'Lone', '123456789', 'Jean', null);

        self::assertSame('Ban', $message->tag);
        self::assertSame('Ban', $message->title);
        self::assertSame('Triche répétée', $message->description);
        self::assertContains(['name' => 'Membre', 'value' => 'Lone (<@123456789>)'], $message->fields);
        self::assertContains(['name' => 'Modérateur', 'value' => 'Jean'], $message->fields);
        self::assertContains(['name' => 'Fiche', 'value' => self::SITE.'/admin/utilisateurs/user-1'], $message->fields);
        self::assertContains(['name' => 'Signalement lié', 'value' => 'report-9 ('.self::SITE.'/admin/moderation)'], $message->fields);
    }

    public function testASuspensionGivesItsEndDate(): void
    {
        $action = ModerationAction::create('admin-1', 'user-1', ModerationAction::ACTION_SUSPEND, 'Insultes', new \DateTimeImmutable('2026-09-27 10:00:00'));

        $message = new ModerationForumMessageFactory(self::SITE)->forAction($action, 'Lone', null, 'Jean', '2026-10-04T10:00:00+00:00');

        self::assertSame('Suspension', $message->tag);
        self::assertContains(['name' => 'Jusqu\'au', 'value' => '04/10/2026 12:00'], $message->fields, 'shown in Paris time');
        self::assertContains(['name' => 'Membre', 'value' => 'Lone (compte Discord non lié)'], $message->fields);
    }

    public function testAWarningAndALiftHaveTheirOwnLabels(): void
    {
        $factory = new ModerationForumMessageFactory(self::SITE);
        $now = new \DateTimeImmutable();

        self::assertSame('Avertissement', $factory->forAction(ModerationAction::create('a', 'u', ModerationAction::ACTION_WARN, 'x', $now), 'M', null, 'A', null)->tag);
        self::assertSame('Levée', $factory->forAction(ModerationAction::create('a', 'u', ModerationAction::ACTION_LIFT, 'x', $now), 'M', null, 'A', null)->tag);
    }
}
