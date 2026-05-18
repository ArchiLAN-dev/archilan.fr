<?php

declare(strict_types=1);

namespace App\Membership\Infrastructure;

use App\Membership\Application\DolibarrClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class DolibarrClient implements DolibarrClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $dolibarrApiUrl,
        private string $dolibarrApiKey,
    ) {
    }

    public function upsertMember(
        string $email,
        string $displayName,
        string $status,
        ?\DateTimeImmutable $expiresAt,
    ): void {
        if ('' === $this->dolibarrApiUrl || '' === $this->dolibarrApiKey) {
            $this->logger->info('dolibarr.client.skipped_no_config', ['email' => $email]);

            return;
        }

        $headers = ['DOLAPIKEY' => $this->dolibarrApiKey];
        $baseUrl = rtrim($this->dolibarrApiUrl, '/');

        $searchResponse = $this->httpClient->request(
            'GET',
            "{$baseUrl}/api/index.php/members?sqlfilters=email%3D'{$email}'",
            ['headers' => $headers],
        );
        $this->ensureSuccess($searchResponse, 'search member', $email);

        $members = $searchResponse->toArray(false);
        $memberData = [
            'email' => $email,
            'login' => $email,
            'lastname' => $displayName,
            'statut' => 'active' === $status ? 1 : 0,
            'datefin' => $expiresAt?->format('Y-m-d') ?? '',
        ];

        $existingId = null;
        if ([] !== $members) {
            $first = $members[0];
            if (is_array($first) && isset($first['id']) && (is_string($first['id']) || is_int($first['id']))) {
                $existingId = (string) $first['id'];
            }
        }

        if (null !== $existingId) {
            $response = $this->httpClient->request(
                'PUT',
                "{$baseUrl}/api/index.php/members/{$existingId}",
                ['headers' => $headers, 'json' => $memberData],
            );
            $this->ensureSuccess($response, 'update member', $email);
        } else {
            $response = $this->httpClient->request(
                'POST',
                "{$baseUrl}/api/index.php/members",
                ['headers' => $headers, 'json' => $memberData],
            );
            $this->ensureSuccess($response, 'create member', $email);
        }
    }

    private function ensureSuccess(ResponseInterface $response, string $operation, string $email): void
    {
        $statusCode = $response->getStatusCode();
        if ($statusCode < 400) {
            return;
        }

        throw new \RuntimeException(sprintf('Dolibarr %s failed for %s with HTTP %d.', $operation, $email, $statusCode));
    }
}
