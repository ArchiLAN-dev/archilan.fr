<?php

declare(strict_types=1);

namespace App\Streaming\Infrastructure;

interface TwitchApiClientInterface
{
    /**
     * Returns the live viewer count, or null when the channel is offline or the API is unavailable.
     */
    public function fetchViewerCount(): ?int;

    /**
     * Batch live check: returns a map of live login => viewer count for the given logins.
     * Offline or unknown logins are absent from the map. Empty input, missing credentials, or an
     * unavailable API yields an empty array (every login treated as offline).
     *
     * @param list<string> $logins
     *
     * @return array<string, int>
     */
    public function fetchLiveLogins(array $logins): array;

    /**
     * Batch profile lookup: returns a map of login => profile image URL for the given logins.
     * Unknown logins are absent. Empty input, missing credentials, or an unavailable API yields `[]`.
     *
     * @param list<string> $logins
     *
     * @return array<string, string>
     */
    public function fetchAvatars(array $logins): array;
}
