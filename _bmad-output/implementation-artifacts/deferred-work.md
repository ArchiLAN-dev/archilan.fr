# Deferred Work

## Deferred from: code review of story-7.7 (2026-06-24) - re-triaged by story 33.8 (2026-07-05)

All four items were re-triaged in story 33.8 (`33-8-tech-debt-cleanup-and-deferred-item-triage.md`,
triage table = audit of record). Outcomes:

- **Twitch outage cached as all-offline (60s)** - **RESOLVED (33.8 D1).** `fetchLiveLogins()` now
  returns `null` when Twitch is unavailable (token fetch failed / every chunk failed), distinct from
  the authoritative empty map; `ParticipantStreamsView::liveMap` caches an outage for only 15s
  (self-heal) vs 60s for authoritative results. Missing credentials still yields `[]` (permanent
  config state, not an outage). Covered by `tests/Unit/Streaming/ParticipantStreamsViewTest`.
- **Label-"twitch" + non-Twitch host yields an attacker-chosen login** - **FORMALLY ACCEPTED (33.8 D2).**
  Unfixable without per-user Twitch OAuth and pointless to fix: a user can put a real
  `twitch.tv/<anyone>` URL anyway; the login is grammar-validated; channel ownership is unverifiable
  by design. Displaying someone else's channel on your own card yields no privilege.
- **Shared embed hidden, not unmounted, below `sm`** - **RESOLVED (33.8 D3).** The embed render is
  now gated by a `useSyncExternalStore` subscription to `(min-width: 640px)` in
  `participant-streams.tsx`, unmounting the iframe below `sm` (the CSS `hidden sm:block` class stays
  as paint guard, and the click handler already used the same media query).
- **Same Twitch login across two distinct users** - **FORMALLY ACCEPTED (33.8 D4).** Pathological
  input (two users claiming one channel); the backend dedups by userId so the data is correct, and a
  single shared embed showing the (single) shared channel is the only sensible rendering.

Nothing remains deferred from story 7.7.

## Deferred from: code review of 33-16-domain-setters-business-methods (2026-07-11)

- **DDD validator text rules can false-positive on literals in comments/strings.** All the
  validator's content rules (clock calls, clock constructs, forbidden imports, and the new AC-D5
  setter rule) run regexes over raw `file_get_contents` - a Domain file whose doc-comment or string
  literal contains a matchable form (e.g. a public set-prefixed declaration example) would fail the
  gate. Latent only (tree is green; the 33.15 self-match lesson is documented). Proper fix is a
  tokenizer-based scan (`token_get_all`) across all rules - natural companion to story 33.17
  (validator extension), not a per-rule patch.
- **`Post`/`Event`: `attachCoverImage` always touches `updatedAt`, sibling `clearCoverImageKey`
  (`?\DateTimeImmutable $now = null`) touches conditionally.** Divergent timestamp contracts on the
  same field within one aggregate. `clearCoverImageKey` predates 33.16 and is out of AC-D5 scope
  (not set-prefixed); align its signature (require the clock) next time those entities are touched.

## Deferred from: epic 37, bascule wss (2026-08-13)

- **L'exposition publique du port du bridge reste ouverte.** L'epic 37 referme le port Archipelago
  de chaque run, pas celui du bridge. Constat du 2026-08-13 : `BRIDGE_HTTP_HOST=archilan.fr` en
  production, donc l'API appelle le bridge d'une session sur `http://archilan.fr:{bridgePort}` -
  elle sort du conteneur vers l'IP publique et revient par le port publié. Les ports
  `25000-25099` sont donc joignables depuis Internet, protégés par le seul token du bridge.

  **Conséquence immédiate à ne pas oublier : ne pas filtrer cette plage au pare-feu.** Ça couperait
  l'API de tous les bridges, donc la génération et le suivi des runs.

  La sortie propre existe déjà : le conteneur s'appelle `archilan-bridge-{sessionId}`
  (`orchestrateur/internal/docker/client.go`) et vit sur le même réseau que `api-web`. L'API
  pourrait le joindre en interne sur `archilan-bridge-{sessionId}:5000`, exactement comme Traefik
  joint `ap-server-{sessionId}:38281`. Ce n'est pas un changement de variable : l'API compose
  aujourd'hui `{host}:{bridgePort}`, il faudrait qu'elle compose un nom par session sur un port
  fixe.

  **À faire après la bascule, pas avant** : c'est elle qui prouvera empiriquement que le routage par
  nom de conteneur fonctionne sur cette machine - l'hypothèse centrale de ce travail. Story à
  rédiger alors, sous le nom « 37.7 - fermer l'exposition publique du bridge ».
