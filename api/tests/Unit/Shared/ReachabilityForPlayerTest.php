<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Support\ReachabilityForPlayer;
use PHPUnit\Framework\TestCase;

final class ReachabilityForPlayerTest extends TestCase
{
    private const array RAW = [
        'reachable_unchecked' => [['id' => 1, 'name' => 'Chest', 'item' => ['id' => 9, 'name' => 'Hookshot']]],
        'reachable_checked' => [['id' => 2, 'name' => 'Shop', 'item' => ['id' => 8, 'name' => 'Bow']]],
        'unreachable_unchecked' => [['id' => 3, 'name' => 'Boss', 'item' => ['id' => 7, 'name' => 'Triforce']]],
        'checked_unreachable' => [],
        'items_received' => [['id' => 8, 'name' => 'Bow', 'count' => 1]],
        'spheres' => [['index' => 0, 'status' => 'past', 'locations' => [['id' => 2, 'name' => 'Shop', 'item' => ['id' => 8, 'name' => 'Bow']]]]],
    ];

    public function testThePlayerViewNamesNoItemAnywhere(): void
    {
        $view = new ReachabilityForPlayer()->forPlayer(self::RAW, true);

        self::assertStringNotContainsString('Hookshot', json_encode($view, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Triforce', json_encode($view, \JSON_THROW_ON_ERROR));
        self::assertSame([['id' => 1, 'name' => 'Chest']], $view['reachable_unchecked']);
        self::assertSame([['index' => 0, 'status' => 'past', 'locations' => [['id' => 2, 'name' => 'Shop']]]], $view['spheres']);
        self::assertSame(self::RAW['items_received'], $view['items_received'], 'what the player received stays');
    }

    public function testTheSpheresGoWhenTheSettingHidesThem(): void
    {
        $view = new ReachabilityForPlayer()->forPlayer(self::RAW, false);

        self::assertArrayNotHasKey('spheres', $view);
    }

    public function testEachReaderGetsItsOwnTopic(): void
    {
        $reachability = new ReachabilityForPlayer();

        self::assertSame('runs/s-1/slots/2/reachable/full', $reachability->topic('s-1', 2, true, false));
        self::assertSame('runs/s-1/slots/2/reachable/spheres', $reachability->topic('s-1', 2, false, true));
        self::assertSame('runs/s-1/slots/2/reachable', $reachability->topic('s-1', 2, false, false));
    }
}
