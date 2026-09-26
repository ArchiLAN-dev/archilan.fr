<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Service;

use App\CatalogSync\Application\Support\CatalogSheetColumns;
use App\CatalogSync\Domain\ValueObject\CatalogEntry;
use App\GameSelection\Domain\Entity\Game;
use App\PersonalRuns\Domain\Entity\Run;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class CatalogSyncService
{
    private const int TTL = 3600;
    private const int GID_MAIN = 58422002;
    private const int GID_BUNDLED = 1675722515;
    private const string SHEETS_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';
    private const string SHEETS_EXPORT_BASE = 'https://docs.google.com/spreadsheets/d';
    private const string FIELDS_MASK = 'sheets.properties.sheetId,sheets.data.rowData.values.textFormatRuns,sheets.data.rowData.values.userEnteredValue,sheets.data.rowData.values.userEnteredFormat.textFormat.link';

    /** @var array<string, string> */
    private const array STABILITY_MAP = [
        'Stable' => 'available',
        'Unstable' => 'experimental',
        'Broken on Main' => 'unavailable',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private string $spreadsheetId,
        private string $googleApiKey,
    ) {
    }

    /**
     * @return list<CatalogEntry>
     */
    public function fetchSheet(): array
    {
        return $this->fetchSheetWithMeta()['entries'];
    }

    public function getCachedAt(): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($this->fetchSheetWithMeta()['cachedAt']);
        } catch (\Throwable) {
            return null;
        }
    }

    public function invalidateCache(): void
    {
        $this->cache->delete('catalog_sync.sheet');
    }

    public function isGoogleApiAvailable(): bool
    {
        return '' !== $this->googleApiKey;
    }

    /**
     * @return array{entries: list<CatalogEntry>, cachedAt: string}
     */
    private function fetchSheetWithMeta(): array
    {
        return $this->cache->get('catalog_sync.sheet', function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);

            return [
                'entries' => $this->doFetch(),
                'cachedAt' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            ];
        });
    }

    /**
     * @return list<CatalogEntry>
     */
    private function doFetch(): array
    {
        if ('' === $this->googleApiKey) {
            return $this->fetchViaCsv();
        }

        return $this->fetchViaApi();
    }

    /**
     * @return list<CatalogEntry>
     */
    private function fetchViaApi(): array
    {
        $url = sprintf(
            '%s/%s?includeGridData=true&key=%s&fields=%s',
            self::SHEETS_API_BASE,
            urlencode($this->spreadsheetId),
            urlencode($this->googleApiKey),
            urlencode(self::FIELDS_MASK),
        );

        $response = $this->httpClient->request('GET', $url);
        /** @var array<string, mixed> $data */
        $data = $response->toArray();

        $entries = [];
        $sheets = is_array($data['sheets'] ?? null) ? $data['sheets'] : [];

        foreach ($sheets as $sheet) {
            if (!is_array($sheet)) {
                continue;
            }

            $props = is_array($sheet['properties'] ?? null) ? $sheet['properties'] : [];
            $sheetId = is_int($props['sheetId'] ?? null) ? $props['sheetId'] : -1;

            if (!in_array($sheetId, [self::GID_MAIN, self::GID_BUNDLED], true)) {
                continue;
            }

            $bundled = self::GID_BUNDLED === $sheetId;
            $gridData = is_array($sheet['data'] ?? null) ? $sheet['data'] : [];
            $grid = is_array($gridData[0] ?? null) ? $gridData[0] : [];
            $rows = is_array($grid['rowData'] ?? null) ? $grid['rowData'] : [];

            $rowCells = [];
            foreach ($rows as $row) {
                $rowCells[] = is_array($row) && is_array($row['values'] ?? null) ? array_values($row['values']) : [];
            }
            $columns = $this->columnsOf(
                array_map(fn (array $cells): array => array_map(fn (mixed $cell): string => is_array($cell) ? $this->getCellText($cell) : '', $cells), $rowCells),
                $bundled,
            );
            if (null === $columns) {
                continue;
            }

            foreach (\array_slice($rowCells, $columns->headerRow + 1) as $cells) {
                $entry = $this->parseApiRow($cells, $bundled, $columns);

                if (null !== $entry) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @param list<mixed> $cells
     */
    private function parseApiRow(array $cells, bool $bundled, CatalogSheetColumns $columns): ?CatalogEntry
    {
        $cellAt = static fn (?int $index): array => null !== $index && is_array($cells[$index] ?? null) ? $cells[$index] : [];

        $nameCell = $cellAt($columns->name);
        $name = $this->getCellText($nameCell);

        if ('' === $name) {
            return null;
        }

        if ($bundled) {
            return new CatalogEntry(
                name: $name,
                availability: 'available',
                prStatus: null,
                adultContent: false,
                notes: null,
                links: [],
                bundledWithAp: true,
            );
        }

        $stabilityCell = $cellAt($columns->stability);
        $stability = $this->getCellText($stabilityCell);
        $availability = self::STABILITY_MAP[$stability] ?? null;
        if (null === $availability) {
            return null;
        }

        $prCell = $cellAt($columns->prStatus);
        $prStatus = $this->getCellText($prCell) ?: null;

        // Links & Downloads - extract hyperlinks from text runs
        $linksCell = $cellAt($columns->links);
        $links = $this->extractLinksFromCell($linksCell);

        // 18+ / Unrated
        $adultCell = $cellAt($columns->adult);
        $adultUev = is_array($adultCell['userEnteredValue'] ?? null) ? $adultCell['userEnteredValue'] : [];
        $adultBool = $adultUev['boolValue'] ?? null;
        if (is_bool($adultBool)) {
            $adultContent = $adultBool;
        } else {
            $adultRaw = strtolower($this->getCellText($adultCell));
            $adultContent = in_array($adultRaw, ['yes', 'oui', 'true', '1'], true);
        }

        // Notes
        $notesCell = $cellAt($columns->notes);
        $notes = $this->getCellText($notesCell) ?: null;

        return new CatalogEntry(
            name: $name,
            availability: $availability,
            prStatus: $prStatus,
            adultContent: $adultContent,
            notes: $notes,
            links: $links,
            bundledWithAp: false,
        );
    }

    /**
     * Extract labeled hyperlinks from a cell's textFormatRuns (multi-link) or cell-level link (single-link).
     *
     * @param array<mixed> $cell
     *
     * @return list<array{label: string, url: string|null}>
     */
    private function extractLinksFromCell(array $cell): array
    {
        $fullText = $this->getCellText($cell);
        $runs = is_array($cell['textFormatRuns'] ?? null) ? $cell['textFormatRuns'] : [];

        $links = [];
        $runCount = count($runs);

        for ($i = 0; $i < $runCount; ++$i) {
            $run = $runs[$i];
            if (!is_array($run)) {
                continue;
            }

            $format = is_array($run['format'] ?? null) ? $run['format'] : [];
            $linkData = is_array($format['link'] ?? null) ? $format['link'] : [];
            $uri = $linkData['uri'] ?? null;

            if (!is_string($uri) || '' === $uri) {
                continue;
            }

            $startIndex = is_int($run['startIndex'] ?? null) ? $run['startIndex'] : 0;
            $nextRun = $runs[$i + 1] ?? null;
            $endIndex = ($i + 1 < $runCount && is_array($nextRun) && is_int($nextRun['startIndex'] ?? null))
                ? $nextRun['startIndex']
                : mb_strlen($fullText);

            $label = trim(mb_substr($fullText, $startIndex, $endIndex - $startIndex));

            if ('' !== $label) {
                $links[] = ['label' => $label, 'url' => $uri];
            }
        }

        if ([] !== $links) {
            return $links;
        }

        // Single-link cell: hyperlink stored at cell level, not in text runs
        $cellUrl = $this->getCellLink($cell);
        if ('' !== $fullText) {
            $links[] = ['label' => $fullText, 'url' => $cellUrl];
        }

        return $links;
    }

    /**
     * @param array<mixed> $cell
     */
    private function getCellText(array $cell): string
    {
        $value = is_array($cell['userEnteredValue'] ?? null) ? $cell['userEnteredValue'] : [];
        $raw = $value['stringValue'] ?? ($value['numberValue'] ?? ($value['boolValue'] ?? ''));

        return trim(is_string($raw) ? $raw : (is_int($raw) || is_float($raw) ? (string) $raw : ''));
    }

    /**
     * @param array<mixed> $cell
     */
    private function getCellLink(array $cell): ?string
    {
        // Run-level hyperlinks (textFormatRuns[].format.link.uri)
        $runs = is_array($cell['textFormatRuns'] ?? null) ? $cell['textFormatRuns'] : [];

        foreach ($runs as $run) {
            if (!is_array($run)) {
                continue;
            }

            $format = is_array($run['format'] ?? null) ? $run['format'] : [];
            $link = is_array($format['link'] ?? null) ? $format['link'] : [];
            $uri = $link['uri'] ?? null;

            if (is_string($uri) && '' !== $uri) {
                return $uri;
            }
        }

        // Cell-level hyperlink fallback (userEnteredFormat.textFormat.link.uri)
        $fmt = is_array($cell['userEnteredFormat'] ?? null) ? $cell['userEnteredFormat'] : [];
        $textFmt = is_array($fmt['textFormat'] ?? null) ? $fmt['textFormat'] : [];
        $cellLink = is_array($textFmt['link'] ?? null) ? $textFmt['link'] : [];
        $uri = $cellLink['uri'] ?? null;

        return is_string($uri) && '' !== $uri ? $uri : null;
    }

    /**
     * Finds the columns of a tab by its header row (story 14.11). A tab without its required columns - the
     * game name, and the stability on the main tab - is skipped rather than read from the wrong columns; a
     * missing optional column leaves its value empty.
     *
     * @param list<list<string>> $rows the texts of the tab's rows
     */
    private function columnsOf(array $rows, bool $bundled): ?CatalogSheetColumns
    {
        $tab = $bundled ? 'bundled' : 'main';
        $columns = CatalogSheetColumns::locate($rows);
        if (null === $columns || (!$bundled && null === $columns->stability)) {
            $this->logger->error('catalog_sync.sheet_header_missing', ['tab' => $tab, 'required' => $bundled ? ['Game'] : ['Game', 'Stability']]);

            return null;
        }
        if (!$bundled && [] !== $columns->missingOptionalColumns()) {
            $this->logger->warning('catalog_sync.sheet_column_missing', ['tab' => $tab, 'columns' => $columns->missingOptionalColumns()]);
        }

        return $columns;
    }

    /**
     * @return list<CatalogEntry>
     */
    private function fetchViaCsv(): array
    {
        $this->logger->warning('catalog_sync.api_key_missing: falling back to CSV export, URLs will be null');

        $entries = [];

        foreach ([self::GID_MAIN => false, self::GID_BUNDLED => true] as $gid => $bundled) {
            $csvUrl = sprintf('%s/%s/export?format=csv&gid=%d', self::SHEETS_EXPORT_BASE, $this->spreadsheetId, $gid);
            $response = $this->httpClient->request('GET', $csvUrl);
            $csv = $response->getContent();

            foreach ($this->parseCsv($csv, $bundled) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @return list<CatalogEntry>
     */
    private function parseCsv(string $csv, bool $bundled): array
    {
        $handle = fopen('php://temp', 'r+b');
        if (false === $handle) {
            return [];
        }

        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (false !== ($row = fgetcsv($handle, 0, ',', '"', ''))) {
            $rows[] = [null] === $row ? [] : array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $row);
        }
        fclose($handle);

        $columns = $this->columnsOf($rows, $bundled);
        if (null === $columns) {
            return [];
        }

        $entries = [];
        foreach (\array_slice($rows, $columns->headerRow + 1) as $cols) {
            $entry = $this->parseCsvRow($cols, $bundled, $columns);

            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param list<string> $cols
     */
    private function parseCsvRow(array $cols, bool $bundled, CatalogSheetColumns $columns): ?CatalogEntry
    {
        $at = static fn (?int $index): string => null !== $index ? trim($cols[$index] ?? '') : '';
        $name = $at($columns->name);

        if ('' === $name) {
            return null;
        }

        if ($bundled) {
            return new CatalogEntry(
                name: $name,
                availability: 'available',
                prStatus: null,
                adultContent: false,
                notes: null,
                links: [],
                bundledWithAp: true,
            );
        }

        $stability = $at($columns->stability);
        $availability = self::STABILITY_MAP[$stability] ?? null;
        if (null === $availability) {
            return null;
        }

        $prStatus = $at($columns->prStatus) ?: null;

        // Links & Downloads - labels only, no URLs in CSV export
        $linksText = $at($columns->links);
        $links = '' !== $linksText ? [['label' => $linksText, 'url' => null]] : [];

        // 18+ / Unrated
        $adultRaw = strtolower($at($columns->adult));
        $adultContent = in_array($adultRaw, ['yes', 'oui', 'true', '1'], true);

        // Notes
        $notes = $at($columns->notes) ?: null;

        return new CatalogEntry(
            name: $name,
            availability: $availability,
            prStatus: $prStatus,
            adultContent: $adultContent,
            notes: $notes,
            links: $links,
            bundledWithAp: false,
        );
    }

    /**
     * Resolve the catalog entry matching a game, mirroring {@see findMatch}'s precedence
     * (catalog sheet name → archipelago game name → name, all trimmed/case-insensitive).
     *
     * Used by the public game detail view to surface the sheet notes/links on demand.
     */
    public function findEntryForNames(?string $catalogSheetName, ?string $archipelagoGameName, string $name): ?CatalogEntry
    {
        $entries = $this->fetchSheet();

        if (null !== $catalogSheetName && '' !== $catalogSheetName) {
            foreach ($entries as $entry) {
                if ($entry->name === $catalogSheetName) {
                    return $entry;
                }
            }
        }

        if (null !== $archipelagoGameName && '' !== $archipelagoGameName) {
            $normalizedArchipelago = mb_strtolower(trim($archipelagoGameName));
            foreach ($entries as $entry) {
                if (mb_strtolower(trim($entry->name)) === $normalizedArchipelago) {
                    return $entry;
                }
            }
        }

        $normalizedName = mb_strtolower(trim($name));
        foreach ($entries as $entry) {
            if (mb_strtolower(trim($entry->name)) === $normalizedName) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Compare sheet entries against existing games and return a categorised diff.
     *
     * @param list<CatalogEntry> $sheetEntries
     * @param list<Game>         $existingGames
     *
     * @return array{
     *   newGames: list<CatalogEntry>,
     *   stabilityChanged: list<array{game: Game, entry: CatalogEntry}>,
     *   removedFromSheet: list<Game>
     * }
     */
    public function computeDiff(array $sheetEntries, array $existingGames): array
    {
        $matchedGameIds = [];
        $newGames = [];
        $stabilityChanged = [];

        foreach ($sheetEntries as $entry) {
            // Exclude already-matched games so a single Game can only appear once
            // in the diff results even if multiple sheet entries could match it.
            $unmatched = array_values(array_filter(
                $existingGames,
                static fn (Game $g): bool => !\in_array($g->getId(), $matchedGameIds, true),
            ));
            $match = $this->findMatch($entry, $unmatched);

            if (null === $match) {
                $newGames[] = $entry;
                continue;
            }

            $matchedGameIds[] = $match->getId();

            if (!$match->isAvailabilityLocked() && $match->getAvailability() !== $entry->availability) {
                $stabilityChanged[] = ['game' => $match, 'entry' => $entry];
            }
        }

        $removedFromSheet = array_values(array_filter(
            $existingGames,
            static function (Game $game) use ($matchedGameIds): bool {
                $csn = $game->getCatalogSheetName();

                return null !== $csn && '' !== $csn && !in_array($game->getId(), $matchedGameIds, true);
            },
        ));

        return [
            'newGames' => $newGames,
            'stabilityChanged' => $stabilityChanged,
            'removedFromSheet' => $removedFromSheet,
        ];
    }

    /**
     * @param list<Game> $existingGames
     */
    private function findMatch(CatalogEntry $entry, array $existingGames): ?Game
    {
        // Step 1: catalog_sheet_name exact match
        foreach ($existingGames as $game) {
            $csn = $game->getCatalogSheetName();
            if (null !== $csn && $csn === $entry->name) {
                return $game;
            }
        }

        // Step 2: archipelago_game_name case-insensitive + trim
        $normalized = mb_strtolower(trim($entry->name));
        foreach ($existingGames as $game) {
            $agn = $game->getArchipelagoGameName();
            if (null !== $agn && mb_strtolower(trim($agn)) === $normalized) {
                return $game;
            }
        }

        // Step 3: name case-insensitive + trim
        foreach ($existingGames as $game) {
            if (mb_strtolower(trim($game->getName())) === $normalized) {
                return $game;
            }
        }

        return null;
    }
}
