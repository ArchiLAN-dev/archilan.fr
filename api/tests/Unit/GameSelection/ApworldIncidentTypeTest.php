<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Enum\ApworldIncidentType;
use PHPUnit\Framework\TestCase;

final class ApworldIncidentTypeTest extends TestCase
{
    public function testAFailingServedApworldFollowsTheServedHash(): void
    {
        self::assertTrue(ApworldIncidentType::PreflightFailed->followsServedApworld());
    }

    public function testUpdateIncidentsAreAboutAnApworldTheGameNeverServed(): void
    {
        // Story 38.6: a rejected candidate is keyed on the candidate hash. Closing it because the game
        // "no longer serves" that hash would close it the moment it opens.
        self::assertFalse(ApworldIncidentType::UpdateRejected->followsServedApworld());
        self::assertFalse(ApworldIncidentType::UpdateAmbiguous->followsServedApworld());
    }

    public function testUpdateIncidentsAreSettledByAPromotion(): void
    {
        self::assertTrue(ApworldIncidentType::UpdateRejected->isSettledByAPromotion());
        self::assertTrue(ApworldIncidentType::UpdateAmbiguous->isSettledByAPromotion());
        self::assertFalse(ApworldIncidentType::PreflightFailed->isSettledByAPromotion());
    }
}
