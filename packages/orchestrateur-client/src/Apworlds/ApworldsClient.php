<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Apworlds;

use Archilan\OrchestratorClient\Apworlds\Response\UploadApworldResult;
use Archilan\OrchestratorClient\Http\HttpTransport;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

final class ApworldsClient
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    public function upload(string $fileContents, string $filename): UploadApworldResult
    {
        $form = new FormDataPart([
            'file' => new DataPart($fileContents, $filename, 'application/octet-stream'),
        ]);

        return UploadApworldResult::fromArray($this->transport->postMultipartJson('/apworlds', $form));
    }

    public function getYamlTemplate(string $hash): string
    {
        return $this->transport->getRaw("/apworlds/{$hash}/yaml");
    }
}
