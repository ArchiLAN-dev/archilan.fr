<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\CatalogSync\Domain\CatalogEntry;
use App\GameSelection\Domain\ArchipelagoGame;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class CatalogSyncService
{
    private const TTL = 3600;
    private const GID_MAIN = 58422002;
    private const GID_BUNDLED = 1675722515;
    private const SHEETS_API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';
    private const SHEETS_EXPORT_BASE = 'https://docs.google.com/spreadsheets/d';
    private const FIELDS_MASK = 'sheets.properties.sheetId,sheets.data.rowData.values.textFormatRuns,sheets.data.rowData.values.userEnteredValue,sheets.data.rowData.values.userEnteredFormat.textFormat.link';

    /** @var array<string, string> */
    private const STABILITY_MAP = [
        'Stable' => 'available',
        'Unstable' => 'experimental',
        'Broken on Main' => 'unavailable',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private LoggerInterface $logger,
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
                'cachedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
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

            foreach ($rows as $i => $row) {
                if (0 === $i) {
                    continue; // skip header row
                }

                if (!is_array($row)) {
                    continue;
                }

                $cells = is_array($row['values'] ?? null) ? array_values($row['values']) : [];
                $entry = $this->parseApiRow($cells, $bundled);

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
    private function parseApiRow(array $cells, bool $bundled): ?CatalogEntry
    {
        $nameCell = is_array($cells[0] ?? null) ? $cells[0] : [];
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

        $stabilityCell = is_array($cells[1] ?? null) ? $cells[1] : [];
        $stability = $this->getCellText($stabilityCell);
        $availability = self::STABILITY_MAP[$stability] ?? null;
        if (null === $availability) {
            return null;
        }

        $prCell = is_array($cells[2] ?? null) ? $cells[2] : [];
        $prStatus = $this->getCellText($prCell) ?: null;

        // col 3: Links & Downloads - extract hyperlinks from text runs
        $linksCell = is_array($cells[3] ?? null) ? $cells[3] : [];
        $links = $this->extractLinksFromCell($linksCell);

        // col 4: 18+ / Unrated
        $adultCell = is_array($cells[4] ?? null) ? $cells[4] : [];
        $adultUev = is_array($adultCell['userEnteredValue'] ?? null) ? $adultCell['userEnteredValue'] : [];
        $adultBool = $adultUev['boolValue'] ?? null;
        if (is_bool($adultBool)) {
            $adultContent = $adultBool;
        } else {
            $adultRaw = strtolower($this->getCellText($adultCell));
            $adultContent = in_array($adultRaw, ['yes', 'oui', 'true', '1'], true);
        }

        // col 5: Notes
        $notesCell = is_array($cells[5] ?? null) ? $cells[5] : [];
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

        $entries = [];
        $firstRow = true;

        while (false !== ($row = fgetcsv($handle, 0, ',', '"', ''))) {
            if ($firstRow) {
                $firstRow = false;
                continue;
            }

            if ([null] === $row) {
                continue;
            }

            $cols = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $row);
            $entry = $this->parseCsvRow($cols, $bundled);

            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        fclose($handle);

        return $entries;
    }

    /**
     * @param list<string> $cols
     */
    private function parseCsvRow(array $cols, bool $bundled): ?CatalogEntry
    {
        $name = trim($cols[0] ?? '');

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

        $stability = trim($cols[1] ?? '');
        $availability = self::STABILITY_MAP[$stability] ?? null;
        if (null === $availability) {
            return null;
        }

        $prStatus = trim($cols[2] ?? '') ?: null;

        // col 3: Links & Downloads - labels only, no URLs in CSV export
        $linksText = trim($cols[3] ?? '');
        $links = '' !== $linksText ? [['label' => $linksText, 'url' => null]] : [];

        // col 4: 18+ / Unrated
        $adultRaw = strtolower(trim($cols[4] ?? ''));
        $adultContent = in_array($adultRaw, ['yes', 'oui', 'true', '1'], true);

        // col 5: Notes
        $notes = trim($cols[5] ?? '') ?: null;

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
     * Compare sheet entries against existing games and return a categorised diff.
     *
     * @param list<CatalogEntry>    $sheetEntries
     * @param list<ArchipelagoGame> $existingGames
     *
     * @return array{
     *   newGames: list<CatalogEntry>,
     *   stabilityChanged: list<array{game: ArchipelagoGame, entry: CatalogEntry}>,
     *   removedFromSheet: list<ArchipelagoGame>
     * }
     */
    public function computeDiff(array $sheetEntries, array $existingGames): array
    {
        $matchedGameIds = [];
        $newGames = [];
        $stabilityChanged = [];

        foreach ($sheetEntries as $entry) {
            $match = $this->findMatch($entry, $existingGames);

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
            static function (ArchipelagoGame $game) use ($matchedGameIds): bool {
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
     * @param list<ArchipelagoGame> $existingGames
     */
    private function findMatch(CatalogEntry $entry, array $existingGames): ?ArchipelagoGame
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
