<?php

declare(strict_types=1);

namespace Archilan\BridgeClient\Admin;

use Archilan\BridgeClient\Admin\Response\MissingItemsResponse;
use Archilan\BridgeClient\Admin\Response\SpoilerResponse;
use Archilan\BridgeClient\Admin\Response\SpheresResponse;
use Archilan\BridgeClient\Http\HttpTransport;

final class AdminClient
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    public function sendCommand(string $command): void
    {
        $this->transport->postVoid('/commands', ['command' => $command]);
    }

    public function pause(): void
    {
        $this->transport->postVoid('/pause');
    }

    public function resume(?string $saveKey = null): void
    {
        $body = $saveKey !== null ? ['saveKey' => $saveKey] : [];
        $this->transport->postVoid('/resume', $body);
    }

    public function deathlink(string $source, ?string $cause = null): void
    {
        $body = ['source' => $source];
        if ($cause !== null) {
            $body['cause'] = $cause;
        }
        $this->transport->postVoid('/deathlink', $body);
    }

    public function missingItems(int $slot): MissingItemsResponse
    {
        return MissingItemsResponse::fromArray(
            $this->transport->getJson('/slots/'.$slot.'/items/missing')
        );
    }

    public function slotSpoiler(int $slot): SpoilerResponse
    {
        return SpoilerResponse::fromArray(
            $this->transport->getJson('/slots/'.$slot.'/spoiler')
        );
    }

    public function spoiler(): SpoilerResponse
    {
        return SpoilerResponse::fromArray($this->transport->getJson('/spoiler'));
    }

    public function spheres(): SpheresResponse
    {
        return SpheresResponse::fromArray($this->transport->getJson('/spheres'));
    }
}
