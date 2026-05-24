<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Apworlds;

use Archilan\OrchestratorClient\Apworlds\Response\ApworldEntry;
use Archilan\OrchestratorClient\Apworlds\Response\TemplateOption;
use Archilan\OrchestratorClient\Apworlds\Response\UploadApworldResult;
use Archilan\OrchestratorClient\Http\HttpTransport;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

/** @implements \IteratorAggregate<int, ApworldEntry> */
final class ApworldsClient implements \IteratorAggregate
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    /** @return \ArrayIterator<int, ApworldEntry> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->list());
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

    /**
     * @return TemplateOption[]
     */
    public function getOptions(string $hash): array
    {
        $data = $this->transport->getJson("/apworlds/{$hash}/options");
        $options = [];
        $rawOptions = $data['options'] ?? null;
        foreach (is_array($rawOptions) ? $rawOptions : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $options[] = TemplateOption::fromArray($item);
            }
        }

        return $options;
    }

    /**
     * @return ApworldEntry[]
     */
    public function list(): array
    {
        $data = $this->transport->getJson('/apworlds');
        $entries = [];
        $rawApworlds = $data['apworlds'] ?? null;
        foreach (is_array($rawApworlds) ? $rawApworlds : [] as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $entries[] = ApworldEntry::fromArray($item);
            }
        }

        return $entries;
    }
}
