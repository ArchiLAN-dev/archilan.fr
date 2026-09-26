<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Support;

/**
 * Where each column of the catalogue sheet is, read from its header row (story 14.11).
 *
 * The community sheet is reorganised from time to time: on 2026-09-26 a banner row had appeared above the
 * header, "18+ / Unrated" had moved before "Links & Downloads" and "Notes" was in the ninth column. Read by
 * position, the GitHub link was then looked for in the 18+ column. Headers are matched by name, ignoring case
 * and spacing, with the names the sheet has used.
 *
 * Pure: works on the texts of the rows.
 */
final readonly class CatalogSheetColumns
{
    private const array NAME = ['game', 'name'];
    private const array STABILITY = ['stability'];
    private const array PR_STATUS = ['pr status'];
    private const array LINKS = ['links & downloads', 'links'];
    private const array ADULT = ['18+ / unrated', '18+'];
    private const array NOTES = ['notes'];

    private function __construct(
        public int $headerRow,
        public int $name,
        public ?int $stability,
        public ?int $prStatus,
        public ?int $links,
        public ?int $adult,
        public ?int $notes,
    ) {
    }

    /**
     * The first row naming the game column is the header; everything above it is a banner.
     *
     * @param list<list<string>> $rows the texts of the rows, in order
     */
    public static function locate(array $rows): ?self
    {
        foreach ($rows as $index => $texts) {
            $name = self::find($texts, self::NAME);
            if (null === $name) {
                continue;
            }

            return new self(
                $index,
                $name,
                self::find($texts, self::STABILITY),
                self::find($texts, self::PR_STATUS),
                self::find($texts, self::LINKS),
                self::find($texts, self::ADULT),
                self::find($texts, self::NOTES),
            );
        }

        return null;
    }

    /**
     * The optional columns of the main tab this header lacks, by their sheet name.
     *
     * @return list<string>
     */
    public function missingOptionalColumns(): array
    {
        return array_keys(array_filter([
            'PR Status' => null === $this->prStatus,
            'Links & Downloads' => null === $this->links,
            '18+ / Unrated' => null === $this->adult,
            'Notes' => null === $this->notes,
        ]));
    }

    /**
     * @param list<string> $texts
     * @param list<string> $names
     */
    private static function find(array $texts, array $names): ?int
    {
        foreach ($texts as $index => $text) {
            if (\in_array(self::normalize($text), $names, true)) {
                return $index;
            }
        }

        return null;
    }

    private static function normalize(string $text): string
    {
        return strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
