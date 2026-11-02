<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Http;

use App\Wallet\Application\Exception\MinimapperzUnauthorizedException;
use App\Wallet\Application\Exception\MinimapperzUnavailableException;
use App\Wallet\Application\Port\MinimapperzClientInterface;
use App\Wallet\Application\Support\MinimapperzEvent;
use App\Wallet\Application\Support\MinimapperzGame;
use App\Wallet\Application\Support\MinimapperzGamePlayer;
use App\Wallet\Application\Support\MinimapperzPlayer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimapperz's organiser API over HTTP (story 49.1). The key travels as a bearer token and never appears in an
 * exception message.
 */
final readonly class MinimapperzHttpClient implements MinimapperzClientInterface
{
    private const float TIMEOUT_SECONDS = 5.0;
    private const float MAX_DURATION_SECONDS = 10.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
    ) {
    }

    public function event(string $slug, string $apiKey): MinimapperzEvent
    {
        $data = $this->get(sprintf('/api/v1/organiser/events/%s', rawurlencode($slug)), $apiKey);

        $eventSlug = $data['slug'] ?? null;
        $name = $data['name'] ?? null;
        $opensAt = $this->date($data['opens_at'] ?? null);
        $closesAt = $this->date($data['closes_at'] ?? null);
        $isOpen = $data['is_open'] ?? null;
        if (!is_string($eventSlug) || !is_string($name) || null === $opensAt || null === $closesAt || !is_bool($isOpen)) {
            throw new MinimapperzUnavailableException('Minimapperz answered an event without its expected fields.');
        }

        return new MinimapperzEvent($eventSlug, $name, $opensAt, $closesAt, $isOpen);
    }

    public function games(string $slug, string $apiKey, ?\DateTimeImmutable $since): array
    {
        $query = null === $since ? [] : ['since' => $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')];
        $data = $this->get(sprintf('/api/v1/organiser/events/%s/games', rawurlencode($slug)), $apiKey, $query);

        $games = [];
        foreach ($this->rows($data) as $row) {
            $id = $row['game'] ?? null;
            $playedAt = $this->date($row['played_at'] ?? null);
            $rounds = $row['rounds'] ?? null;
            $players = $row['players'] ?? null;
            if (!is_string($id) || null === $playedAt || !is_int($rounds) || !is_array($players)) {
                throw new MinimapperzUnavailableException('Minimapperz answered a game without its expected fields.');
            }
            $gamePlayers = [];
            foreach ($players as $player) {
                $gamePlayers[] = $this->gamePlayer($player);
            }
            $games[] = new MinimapperzGame($id, $playedAt, $rounds, $gamePlayers);
        }

        return $games;
    }

    public function players(string $slug, string $apiKey): array
    {
        $data = $this->get(sprintf('/api/v1/organiser/events/%s/players', rawurlencode($slug)), $apiKey);

        $players = [];
        foreach ($this->rows($data) as $row) {
            $username = $row['username'] ?? null;
            $code = $row['code'] ?? null;
            $games = $row['games'] ?? null;
            $wins = $row['wins'] ?? null;
            if (!is_string($username) || (null !== $code && !is_string($code)) || !is_int($games) || !is_int($wins)) {
                throw new MinimapperzUnavailableException('Minimapperz answered a player without its expected fields.');
            }
            $players[] = new MinimapperzPlayer($username, $code, $games, $wins);
        }

        return $players;
    }

    private function gamePlayer(mixed $player): MinimapperzGamePlayer
    {
        if (!is_array($player)) {
            throw new MinimapperzUnavailableException('Minimapperz answered a game player that is not an object.');
        }
        $username = $player['username'] ?? null;
        $code = $player['code'] ?? null;
        $points = $player['points'] ?? null;
        $place = $player['place'] ?? null;
        $won = $player['won'] ?? null;
        if (!is_string($username) || (null !== $code && !is_string($code)) || !is_int($points) || !is_int($place) || !is_bool($won)) {
            throw new MinimapperzUnavailableException('Minimapperz answered a game player without its expected fields.');
        }

        return new MinimapperzGamePlayer($username, $code, $points, $place, $won);
    }

    /**
     * The `data` list of a collection answer.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<array<array-key, mixed>>
     */
    private function rows(array $data): array
    {
        $rows = $data['data'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new MinimapperzUnavailableException('Minimapperz answered a collection without its data list.');
        }
        $valid = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new MinimapperzUnavailableException('Minimapperz answered a collection row that is not an object.');
            }
            $valid[] = $row;
        }

        return $valid;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<array-key, mixed>
     */
    private function get(string $path, string $apiKey, array $query = []): array
    {
        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/').$path, [
                'auth_bearer' => $apiKey,
                'headers' => ['Accept' => 'application/json'],
                'query' => $query,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new MinimapperzUnauthorizedException('Minimapperz refused the API key for this event.');
            }
            if (200 !== $status) {
                throw new MinimapperzUnavailableException(sprintf('Minimapperz answered HTTP %d.', $status));
            }

            return $response->toArray(false);
        } catch (MinimapperzUnauthorizedException|MinimapperzUnavailableException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new MinimapperzUnavailableException(sprintf('Minimapperz could not be read (%s).', $e::class), 0, $e);
        }
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
