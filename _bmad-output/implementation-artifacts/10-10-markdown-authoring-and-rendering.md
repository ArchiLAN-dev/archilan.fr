# Story 10.10: Markdown authoring and rendering for content fields

**Status:** in progress
**Epic:** 10 - Identité visuelle, médias, pages publiques
**Date:** 2026-07-21

## Story

As an author (admin **or** member),
I want the long-form text fields to accept simple markdown with a light editor and a preview,
so that descriptions, tutorials, contributions, bios and comments can have structure (titres, gras, listes, liens) instead of being a flat block of text.

## Context

Today the only rich authoring in the app is `RichTextEditor` (TipTap, already installed), used **exclusively** by `admin-post-form` for news/recaps. It emits **HTML** (`editor.getHTML()`), stored as-is and rendered on the public post page through `dangerouslySetInnerHTML` - with **no sanitiser anywhere in the project** (no DOMPurify, no sanitize-html). That is tenable only because posts are admin-authored.

Every other long-form field is a plain `<textarea>` rendered as flat text. There are 17 files containing textareas, but most are technical (YAML editors) or short internal fields (motifs de modération, notes d'adhésion) that must stay plain.

This story introduces a **light markdown** path - editor + renderer - and applies it to the content fields that benefit, including **community-authored** ones. That last part is what drives the security posture below: extending the current HTML approach to non-admin authors would be an XSS hole, so markdown here is deliberately rendered **without any raw HTML**.

### Decisions

- **Renderer: `react-markdown`, rendering to React elements** - never an HTML string, so `dangerouslySetInnerHTML` is not involved and raw HTML in the source is inert by construction. **`rehype-raw` must NOT be added.** This is the single most important constraint of the story: it is what makes community-authored markdown safe in a codebase with no sanitiser.
- **Editor: light, not a true WYSIWYG** - a `<textarea>` keeping the markdown source visible, plus a small toolbar (gras, italique, titre, liste, lien, code) and an *Aperçu* toggle. Matches the "rien de trop complexe" brief and avoids a second heavy editor framework.
- **Storage: markdown text in the existing TEXT columns** - no migration, no new field. Existing plain text stays valid markdown.
- **Restricted element set** - paragraphes, titres (h3+ only, so authored content cannot outrank page titles), gras/italique, listes, liens, code, citations. **Images are disabled** on community-authored surfaces (arbitrary remote images are a tracking/abuse vector); admin surfaces may allow them only if `next/image` policy permits.
- **Links get `rel="nofollow ugc noopener"` + `target="_blank"`** on community-authored surfaces.
- **Single newlines must still break** (`remark-breaks` or equivalent). Standard markdown collapses them, but the bio, les commentaires and les étapes de tutoriel render with `whitespace-pre-line` **today** - dropping that behaviour would silently reflow every existing user text. Non-negotiable.
- **News/recaps keep TipTap/HTML, untouched.** Migrating them to markdown is out of scope; mixing paradigms in one story would balloon it.

### Surfaces: notes after mapping the code

- **Contribution message (surface 5) has no public render site** - it is displayed only in the admin moderation panel (`contributions-moderation-panel.tsx`); on approval it is the contribution's *steps* that get merged into the game. **Kept in scope on the author's decision**: contributors benefit from structuring their message even though only moderators read it, and the moderation panel is the render target. It is also **community-authored and currently unbounded**, so it belongs in the length-limit work.
- **Achievement description (surface 4)** renders in a dense shared `text-xs` card (`achievement-card.tsx`, backing both the catalogue and profile achievements). Block-level markdown does not fit that layout: restrict it to the **inline subset** (gras/italique/code/lien), no headings or lists.

### Known caveats to validate

1. Existing stored text becomes markdown, so a legacy value containing `#`, `*` or `_` will render differently. Surfaces 1, 2 and 4 collapse newlines today, so markdown is a strict improvement there; surfaces 3, 6 and 7 already use `whitespace-pre-line`, which is precisely why the single-newline decision above exists. Spot-check real data before rollout.
2. **Event and game descriptions feed `generateMetadata` verbatim** (`evenements/[eventSlug]/page.tsx`, `jeux/[slug]/page.tsx`) - raw markdown syntax would leak into `<meta name="description">` and OG cards. They need a plain-text projection, not the rendered markdown.
3. Two explicit code contracts state the field is plain text and never HTML - `InstallStepsNormalizer` (docblock) and `install-steps-view.tsx` (file header). This story changes them; the comments must be updated deliberately, not left lying.

## Acceptance Criteria

1. A shared `Markdown` component renders markdown to React elements with the restricted element set, no raw HTML passthrough, and no `dangerouslySetInnerHTML`.
2. A shared `MarkdownEditor` component provides a textarea, a toolbar (gras, italique, titre, liste, lien, code) and an *Aperçu* toggle that uses the same `Markdown` renderer, so preview and public output cannot diverge.
3. **Admin-authored surfaces** use the editor and render markdown publicly: description d'événement, description de jeu, descriptions d'étapes de tutoriel; description de succès in the **inline subset only**.
4. **Community-authored surfaces** use the editor and render markdown: bio de profil, commentaires de profil, message de contribution (rendered in the admin moderation panel) - with images disabled and `nofollow ugc noopener` on links.
5. A raw-HTML payload (`<script>`, `<img onerror=…>`, `<iframe>`) typed into any of these fields is rendered as inert text, never executed - covered by a test.
6. Existing content still displays correctly: no crash, no loss, and **single newlines still break** so current `whitespace-pre-line` formatting is preserved - covered by a test.
7. `generateMetadata` for the event and game pages emits a **plain-text projection** of the description (markdown syntax stripped), never the raw source.
8. The four currently unbounded fields - event description, game description, achievement description (admin) and contribution message (community) - get a length limit enforced frontend + API. *(Bio and comments already enforce 2000 on both sides; install-step descriptions are already capped at 2000 server-side - though by silent truncation, which is worth revisiting.)*
9. Gates green both sides: `composer gates` and `pnpm gates`.

## Tasks / Subtasks

- [x] **Task 1 - Shared components** (AC 1, 2, 5, 6). Add `react-markdown` (+ `remark-gfm`, `remark-breaks`); build `Markdown` (restricted elements, `inline` variant, link/image policy props) and `MarkdownEditor` (toolbar + preview). Add a `markdownToPlainText` helper for metadata. Jest: raw HTML inert, restricted elements, link attributes, single newlines break.
- [x] **Task 2 - Admin-authored surfaces** (AC 3, 7, 8). Swap the textarea for `MarkdownEditor` in the event form, game editor (+ new-game / guided-creation), install-steps editor, achievements dashboard; render with `Markdown` on the matching public sites (`evenements/[eventSlug]`, `game-detail.tsx`, `install-steps-view.tsx`, `achievement-card.tsx` inline). Feed `generateMetadata` from `markdownToPlainText`. Add the three missing length limits. Update the two "plain text, never HTML" contracts.
- [x] **Task 3 - Community-authored surfaces** (AC 4, 6, 8). Same swap for la bio de profil, les commentaires de profil and le message de contribution (rendered in `contributions-moderation-panel.tsx`); images off, `nofollow ugc noopener`. Bio and comment limits already exist - verify, do not duplicate; the contribution message needs one added.
- [ ] **Task 4 - Legacy content check** (caveat 1). Spot-check existing stored values for markdown-significant characters; report anything that would visibly change.
- [x] **Task 5 - MiniMarkdown** (Dev Notes). Decided: **kept**, with the reasoning documented on the component itself. See below.
- [ ] **Task 6 - Gates** (AC 9). `composer gates` + `pnpm gates` green.

## Dev Notes

- `MiniMarkdown` (`yaml-option-editor.tsx`) renders a tiny markdown subset **safely** (React nodes, no `dangerouslySetInnerHTML`) - bold/italic/code plus `-` bullets.

  **Task 5 decision: keep it, do not unify.** What it renders is not authored markdown: option help text is scraped from the apworld's YAML template comments by `extractDescription`, which strips `#` plus a single space and leaves the remaining indentation intact. Apworld authors routinely indent their value enumerations, so the shared renderer would read those lines as **indented code blocks** and turn readable help into monospace boxes; `##` and `1.` lines would likewise become headings and ordered lists nobody wrote. The upside would have been clickable links and ordered lists *inside a tooltip* - not worth regressing every apworld's help text. Unifying would first require dedenting in `extractDescription` and re-checking against real apworld templates; that is a separate piece of work, not a silent duplicate. The rationale is recorded on the component so the next reader does not re-litigate it.
- Bundle cost lands on public pages; keep the plugin set minimal and prefer rendering in Server Components where the page already is one.
- Backend work is limited to the three new length limits (Task 2); every target column is already TEXT, so **no migration**.
- Unrelated trap spotted while mapping, worth recording: the post page decides HTML-vs-plaintext with `post.body[0].trimStart().startsWith("<")`. If markdown content ever lands in `post.body`, a body starting with `<` would be injected through `dangerouslySetInnerHTML`. Out of scope here - do not feed this story's output into `post.body`.

### Delivery

Tasks 1-2 (foundation + admin surfaces) and Task 3 (community surfaces) are intended as **two PRs** - the community half carries the security-sensitive changes and deserves its own review.

### Project Structure Notes

- `frontend/src/components/markdown/markdown.tsx` (new)
- `frontend/src/components/markdown/markdown-editor.tsx` (new)
- `frontend/src/features/admin/admin-event-form.tsx`, `admin-game-editor.tsx`, `admin-new-game-page.tsx`, `admin-guided-game-creation.tsx`, `admin-achievements-dashboard.tsx`
- `frontend/src/features/games/install-steps-editor.tsx`, `game-contribution-form.tsx`
- `frontend/src/features/community/community-profile-customization-form.tsx`, `profile-comments.tsx`
- the matching public render sites (event detail, game detail, achievements, player profile)

### References

- [Source: frontend/src/features/admin/rich-text-editor.tsx (existing TipTap path, HTML output)]
- [Source: frontend/src/app/(public)/actualites/[postSlug]/page.tsx (dangerouslySetInnerHTML, no sanitiser)]
- [Source: frontend/src/features/events/yaml-option-editor.tsx (MiniMarkdown)]

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (Claude Code, 1M context).

### Completion Notes List

### File List
