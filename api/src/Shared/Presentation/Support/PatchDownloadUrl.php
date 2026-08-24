<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Support;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;

/**
 * Signed, shareable links to a generated output file (story 16.16).
 *
 * A player has to be able to copy the link of their patch and send it to someone who has no
 * ArchiLAN account: the file is what they need to patch their ROM, and the recipient is often the
 * person who will actually play that slot. The authenticated routes cannot serve that, so the
 * public one carries its authorization in the URL itself.
 *
 * Three surfaces emit these links (private runs, event registrations, weekly runs) and they read
 * from two different places, so the location is part of the signed query rather than something the
 * public route has to resolve from a caller it does not have.
 *
 * The signature covers the whole request URI, path and query: a link is valid for the one file it
 * names, and altering the filename or the location invalidates it. There is deliberately **no
 * expiration** - runs sleep for weeks and the recipient must not have to ask for a fresh link. The
 * accepted cost is that a leaked link stays valid; nothing revokes it short of rotating the
 * application secret, which would break everyone's links at once.
 *
 * Signing and checking both work on the *request URI* (path + query) rather than the absolute URL,
 * so the reverse proxy in front of the API cannot make a signature stop matching by rewriting the
 * scheme or the host.
 */
final readonly class PatchDownloadUrl
{
    /** Path of the public route, shared by the emitters and by the controller that serves it. */
    public const string PATH = '/api/v1/public/patches/';

    public function __construct(private UriSigner $signer)
    {
    }

    /** A file held inside a session's durable output archive on object storage. */
    public function forArchive(string $outputKey, string $filename): string
    {
        return $this->sign($filename, ['archive' => $outputKey]);
    }

    /** A file sitting in a session's output directory on the shared workspace volume. */
    public function forWorkspace(string $sessionId, string $filename): string
    {
        return $this->sign($filename, ['workspace' => $sessionId]);
    }

    public function isValid(Request $request): bool
    {
        return $this->signer->check($request->getRequestUri());
    }

    /** @param array<string, string> $query */
    private function sign(string $filename, array $query): string
    {
        return $this->signer->sign(self::PATH.rawurlencode($filename).'?'.http_build_query($query));
    }
}
