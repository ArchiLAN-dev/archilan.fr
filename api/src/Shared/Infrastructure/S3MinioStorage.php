<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Psr\Http\Message\StreamInterface;

final class S3MinioStorage implements MinioStorageInterface
{
    private readonly S3Client $client;

    public function __construct(
        string $endpoint,
        string $accessKey,
        string $secretKey,
        string $region = 'us-east-1',
    ) {
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);
    }

    public function upload(string $bucket, string $key, string $contents): void
    {
        try {
            $this->client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $contents,
            ]);
        } catch (AwsException $e) {
            throw new \RuntimeException('MinIO upload failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function download(string $bucket, string $key): string
    {
        try {
            $result = $this->client->getObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);

            $body = $result->get('Body');

            if (!$body instanceof StreamInterface) {
                throw new \RuntimeException('Unexpected MinIO response body type.');
            }

            return $body->getContents();
        } catch (AwsException $e) {
            throw new \RuntimeException('MinIO download failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function exists(string $bucket, string $key): bool
    {
        try {
            return $this->client->doesObjectExist($bucket, $key);
        } catch (AwsException $e) {
            throw new \RuntimeException('MinIO existence check failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function presignedUrl(string $bucket, string $key, int $ttlSeconds): string
    {
        $cmd = $this->client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $key,
        ]);

        $request = $this->client->createPresignedRequest($cmd, sprintf('+%d seconds', $ttlSeconds));

        return (string) $request->getUri();
    }
}
