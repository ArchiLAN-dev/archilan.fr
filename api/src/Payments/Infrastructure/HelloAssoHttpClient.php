<?php

declare(strict_types=1);

namespace App\Payments\Infrastructure;

use App\Payments\Application\HelloAssoConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class HelloAssoHttpClient
{
    public function __construct(
        private HelloAssoConfig $config,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function getConfig(): HelloAssoConfig
    {
        return $this->config;
    }

    public function getAccessToken(): string
    {
        $response = $this->httpClient->request('POST', $this->config->getOAuthBaseUrl().'/oauth2/token', [
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->config->getClientId(),
                'client_secret' => $this->config->getClientSecret(),
            ],
        ]);

        $data = $response->toArray();
        $token = $data['access_token'] ?? null;

        if (!is_string($token) || '' === $token) {
            throw new \RuntimeException('HelloAsso OAuth2 response did not contain a valid access_token.');
        }

        return $token;
    }

    /**
     * @return list<array{orderId: int, status: string, amountCents: int, payerEmail: string|null, payerFirstName: string|null, payerLastName: string|null, paidAt: \DateTimeImmutable|null}>
     */
    public function fetchFormItems(string $formType, string $formSlug, string $accessToken): array
    {
        $orgSlug = $this->config->getOrganizationSlug();
        $apiFormType = $this->mapToApiFormType($formType);
        $url = sprintf('%s/organizations/%s/forms/%s/%s/items', $this->config->getApiBaseUrl(), $orgSlug, $apiFormType, $formSlug);

        $allItems = [];
        $pageIndex = 1;
        $pageSize = 100;

        do {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer '.$accessToken],
                'query' => ['pageSize' => $pageSize, 'pageIndex' => $pageIndex, 'withDetails' => 'true'],
            ]);

            $data = $response->toArray();
            $rawItems = $data['data'] ?? [];
            $totalCount = is_array($data['pagination'] ?? null) && is_int($data['pagination']['totalCount'] ?? null)
                ? $data['pagination']['totalCount']
                : 0;

            if (!is_array($rawItems)) {
                break;
            }

            foreach ($rawItems as $rawItem) {
                $parsed = $this->parseItem($rawItem);
                if (null !== $parsed) {
                    $allItems[] = $parsed;
                }
            }

            ++$pageIndex;
        } while (count($allItems) < $totalCount && [] !== $rawItems);

        return $allItems;
    }

    private function mapToApiFormType(string $formType): string
    {
        return match ($formType) {
            HelloAssoConfig::FORM_TYPE_EVENT => 'Event',
            HelloAssoConfig::FORM_TYPE_MEMBERSHIP => 'Membership',
            HelloAssoConfig::FORM_TYPE_SHOP => 'PaymentForm',
            default => $formType,
        };
    }

    /**
     * @return array{orderId: int, status: string, amountCents: int, payerEmail: string|null, payerFirstName: string|null, payerLastName: string|null, paidAt: \DateTimeImmutable|null}|null
     */
    private function parseItem(mixed $rawItem): ?array
    {
        if (!is_array($rawItem)) {
            return null;
        }

        $order = $rawItem['order'] ?? null;
        if (!is_array($order)) {
            return null;
        }

        $orderId = $order['id'] ?? null;
        if (!is_int($orderId)) {
            return null;
        }

        $status = $order['status'] ?? '';
        if (!is_string($status)) {
            $status = 'unknown';
        }

        $amountData = $order['amount'] ?? null;
        $amountCents = is_array($amountData) && is_int($amountData['total'] ?? null) ? $amountData['total'] : 0;

        $payer = $order['payer'] ?? null;
        $payerEmail = is_array($payer) && is_string($payer['email'] ?? null) && '' !== $payer['email'] ? $payer['email'] : null;
        $payerFirstName = is_array($payer) && is_string($payer['firstName'] ?? null) && '' !== $payer['firstName'] ? $payer['firstName'] : null;
        $payerLastName = is_array($payer) && is_string($payer['lastName'] ?? null) && '' !== $payer['lastName'] ? $payer['lastName'] : null;

        $paidAt = null;
        $dateStr = $order['date'] ?? null;
        if (is_string($dateStr) && '' !== $dateStr) {
            try {
                $paidAt = new \DateTimeImmutable($dateStr);
            } catch (\Exception) {
            }
        }

        return [
            'orderId' => $orderId,
            'status' => $status,
            'amountCents' => $amountCents,
            'payerEmail' => $payerEmail,
            'payerFirstName' => $payerFirstName,
            'payerLastName' => $payerLastName,
            'paidAt' => $paidAt,
        ];
    }
}
