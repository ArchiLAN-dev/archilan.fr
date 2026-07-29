# SEO measurement plan (epic 34)

How we observe whether the epic-34 SEO work moves rankings, and the off-site actions handed to the
association. Written 2026-07-16 (baseline date: set to the day the GSC property is verified).

## 1. Google Search Console (GSC)

The indexable surface (sitemap, metadata, structured data, ISR, fonts/headers, keyword copy) is all in
place. GSC is how we watch impressions, clicks and average position over time.

### Verify the property (human)

Two options - pick one:

- **DNS TXT** (nothing to deploy): add the TXT record GSC gives you to the `archilan.fr` DNS zone, then
  click Verify. Preferred if you manage DNS.
- **HTML meta tag** (no code change either): copy the token from GSC's "HTML tag" method, set the env var
  `NEXT_PUBLIC_GSC_VERIFICATION=<token>` on the frontend deployment, redeploy, then click Verify. The root
  layout renders `<meta name="google-site-verification" ...>` automatically when that env is set (empty ->
  no tag).

### Submit the sitemap (human, after verification)

In GSC -> Sitemaps, submit `https://archilan.fr/sitemap.xml` (live since story 34.1). Impression data starts
accruing within a few days.

## 2. Target-query watchlist (from the 34.6 keyword map)

Track these in GSC (Performance -> Queries) once data flows. Grouped by page.

**Cluster A - Archipelago / randomizer (francophone)**

| Page | Watch these queries |
|---|---|
| `/aide/archipelago` | comment jouer à Archipelago, installer Archipelago, client Archipelago, première partie multiworld |
| `/jeux` | jeux compatibles Archipelago, catalogue randomizer, jeux multiworld |
| `/jeux/{slug}` | {jeu} Archipelago, {jeu} randomizer, {jeu} multiworld, seed {jeu} |
| `/runs-hebdo` | runs Archipelago hebdomadaires, seed de la semaine, classement multiworld |
| `/` (home) | Archipelago multiworld en France, randomizer coopératif, communauté Archipelago francophone |

**Cluster B - LAN local / événementiel**

| Page | Watch these queries |
|---|---|
| `/evenements` | LAN Archipelago en France, événement multiworld, soirée jeux vidéo coopératif |
| `/actualites` | actualités Archipelago ArchiLAN, récaps LAN |

Suggested cadence: review monthly - impressions (is Google showing us?), position (are we climbing?),
CTR (do the titles/descriptions from 34.2 earn clicks?).

## 3. Lighthouse baseline

### Advisory CI run (home page)

The `Lighthouse (advisory)` GitHub workflow (`.github/workflows/lighthouse.yml`) is **manual**
(`workflow_dispatch`), never a required check, and every assertion is **warn-level** - it surfaces scores,
it never blocks a merge. It measures the **home page shell** (CI has no API, so events degrade to empty).

Run it: Actions -> "Lighthouse (advisory)" -> Run workflow. The step logs a temporary-public-storage report
URL. Config + thresholds live in `frontend/lighthouserc.json`:

| Category | Warn threshold |
|---|---|
| performance | 0.80 |
| accessibility | 0.90 |
| seo | 0.90 |
| best-practices | 0.90 |

These are starting targets to watch, not gates. Tighten once a deployed baseline exists.

### Full mobile before/after (deployed env - human)

The **event detail page needs real data** (no API in CI), and true Core Web Vitals need the production CDN +
images. So the epic's before/after mobile Lighthouse (34.5 AC5) is run on a **deployed environment**:

1. Run Lighthouse (mobile) on the home page and one event detail page.
2. Record the scores + CWV (LCP, CLS, INP) below.
3. Confirm no regression after the epic-34 changes are live.

| Date | Page | Perf | A11y | BP | SEO | LCP | CLS | Notes |
|---|---|---|---|---|---|---|---|---|
| 2026-07-29 | `/` | 45 | 100 | 96 | 100 | 95.6 s | 0 | post-epic deployed baseline; sunk by two raw static assets (below) |
| 2026-07-29 | event detail | 92 | 96 | 96 | 100 | 1.8 s | - | post-epic deployed baseline - the epic's target state |

Baseline findings (2026-07-29, Lighthouse 12 mobile, local machine against https://archilan.fr):

- **SEO / a11y / best-practices are done**: 96-100 on both pages. The remaining gap is home performance.
- **Root cause 1 (fixed in-repo)**: `public/images/events/lan-photo-1.webp` was a raw 6000x4000
  **10.6 MB** export and `public/images/logo.webp` a 2048x2048 lossless **2.4 MB** file - 18.8 MB page
  weight, LCP 95.6 s throttled. Recompressed to 128 KB (1920 px, q72) and 51 KB (512 px, q85); a jest
  gate (`src/lib/public-images-weight.test.ts`) now fails any `public/images` file over 500 KB. The fix
  reaches production at the next release.
- **Root cause 2 (to watch after next deploy)**: the production `/_next/image` optimizer returns
  local static files **byte-identical** (`X-Nextjs-Cache: HIT` on original bytes, at every width) - it
  cached failed optimizations, the classic signature of `sharp` missing/failing in the standalone
  Docker runtime. With lean sources the impact is now minor, but after the next deploy check
  `curl -sI ".../_next/image?url=%2Fimages%2Flogo.webp&w=96&q=75"` returns a size well under 51 KB;
  if it still passes through, add `sharp` as an explicit dependency so Next's standalone tracing
  bundles its linux binaries.
- Re-run this table after the release that carries the compressed assets.

## 4. Off-site actions (handed to the association - out of repo scope)

Locked epic decision: off-site SEO is not built in the repo. These are human actions that materially help
local + community discoverability:

- [ ] **Google Business Profile** for the association (name, category "association / gaming", the
      Clermont-Ferrand siège address already public in the legal mentions, links to archilan.fr + socials).
- [ ] **Association / gaming directories**: list ArchiLAN on relevant FR association registries and
      Archipelago/randomizer community hubs.
- [ ] **Partner-community backlinks**: get links from Archipelago Discord communities, streamer channels and
      event partners pointing at the relevant pages (home, `/aide/archipelago`, game pages).
- [ ] **Social profile consistency**: ensure Twitch/Discord profiles link back to archilan.fr (reinforces
      the `sameAs` in the Organization JSON-LD from 34.3).

## References

- Sitemap: `frontend/src/app/sitemap.ts` (`/sitemap.xml`). Robots: `frontend/src/app/robots.ts`.
- Keyword map: `_bmad-output/implementation-artifacts/34-6-editorial-keywords.md`.
- Structured data (`sameAs`, Organization): `frontend/src/lib/structured-data.ts`.
- GSC tag wiring: `frontend/src/lib/env.ts` (`gscVerification`), `frontend/src/app/layout.tsx`.
