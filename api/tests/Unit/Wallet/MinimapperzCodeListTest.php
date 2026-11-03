<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Wallet\Application\Support\MinimapperzCodeList;
use PHPUnit\Framework\TestCase;

final class MinimapperzCodeListTest extends TestCase
{
    public function testOneCodePerLineEachOnceInOrder(): void
    {
        $list = MinimapperzCodeList::parse("B-2\r\n\n  A-1  \nB-2\n\tC-3");

        self::assertSame(['B-2', 'A-1', 'C-3'], $list->codes);
        self::assertSame([], $list->invalid);
    }

    public function testALineWithASpaceOrTooLongIsSetAside(): void
    {
        $list = MinimapperzCodeList::parse("A-1\nnot a code\n".str_repeat('x', 101));

        self::assertSame(['A-1'], $list->codes);
        self::assertSame(['not a code', str_repeat('x', 101)], $list->invalid);
    }

    public function testNumericLookingCodesStayStrings(): void
    {
        self::assertSame(['0042', '17'], MinimapperzCodeList::parse("0042\n17")->codes);
    }
}
