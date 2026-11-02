<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

use App\Wallet\Domain\Entity\MinimapperzCode;

/**
 * Story 49.2: the codes an admin pasted, one per line. Blank lines and repeats are dropped; a line with a space inside
 * or longer than a code may be is set aside, so a typo is shown rather than imported.
 */
final readonly class MinimapperzCodeList
{
    /**
     * @param list<string> $codes   valid codes, each once, in the pasted order
     * @param list<string> $invalid lines set aside
     */
    private function __construct(
        public array $codes,
        public array $invalid,
    ) {
    }

    public static function parse(string $text): self
    {
        $codes = [];
        $invalid = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $code = trim($line);
            if ('' === $code) {
                continue;
            }
            if (mb_strlen($code) > MinimapperzCode::MAX_LENGTH || 1 === preg_match('/\s/u', $code)) {
                $invalid[] = $code;
                continue;
            }
            $codes[$code] = true;
        }

        return new self(array_map(strval(...), array_keys($codes)), $invalid);
    }
}
