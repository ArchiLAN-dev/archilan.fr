# Deferred Work

## Deferred from: code review of story-7.7 (2026-06-24)

- **Twitch outage cached as all-offline (60s)** - `ParticipantStreamsView::liveMap` caches an empty live map for the full 60s TTL, so a transient Twitch outage shows everyone offline for up to a minute. Shortening the empty-result TTL would self-heal faster but re-hit Helix every few seconds for the common "nobody is live" case, burning quota. Design tradeoff - left as 60s.
- **Label-"twitch" + non-Twitch host yields an attacker-chosen login** - `TwitchLinkResolver` accepts a path segment off a foreign host when the link label is "twitch". Harmless (the login is grammar-validated and channel ownership is unverifiable without per-user Twitch OAuth, which a user could spoof with a real `twitch.tv/<anyone>` URL anyway). Out of scope.
- **Shared embed hidden, not unmounted, below `sm`** - resizing the viewport below 640px after selecting a channel keeps the iframe in the DOM (loading) under `hidden sm:block`. Minor perf/consistency; would need a reactive media-query to unmount.
- **Same Twitch login across two distinct users** - both cards highlight as active and the single shared embed is ambiguous. Pathological (backend dedups by userId, not login).
