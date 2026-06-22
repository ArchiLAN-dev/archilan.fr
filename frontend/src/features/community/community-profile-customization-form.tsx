"use client";

import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";
import { AlertCircle, ArrowDown, ArrowUp, Check, CheckCircle, ImagePlus, Loader2, Plus, Search, Trash2, X } from "lucide-react";

import { ProfileAvatar } from "@/features/players/profile-avatar";
import { getAllPublicGames, type PublicGame } from "@/features/games/public-games-api";
import { AVATAR_FRAME_KEYS, AVATAR_FRAMES, type AvatarFrameCategory } from "./avatar-frames";
import { AvatarFrame } from "./avatar-frame";
import { CommunityLoadingSkeleton } from "./community-loading-skeleton";
import { BANNER_PRESETS } from "./banner-presets";
import { ProfileBanner } from "./profile-banner";
import { isKnownLinkType, LINK_TYPES, OTHER_LINK_TYPE, resolveLinkType } from "./social-links";
import {
  AUDIENCES,
  fetchMyCommunityProfile,
  removeCommunityAvatar,
  SHOWCASE_WIDGETS,
  SHOWCASE_WIDGET_LABELS,
  updateMyCommunityProfile,
  uploadCommunityAvatar,
  type EditableFavoriteGame,
  type EditableSocialLink,
  type MyCommunityProfile,
} from "./community-profile-api";

const MAX_SOCIAL_LINKS = 5;
const MAX_FAVORITES = 6;
const FRAME_CATEGORIES: readonly AvatarFrameCategory[] = ["Couleurs", "Néon", "Effets"];

const MAX_DISPLAY_NAME = 80;
const MAX_TAGLINE = 120;
const MAX_PRONOUNS = 40;
const MAX_BIO = 2000;
const FAVORITE_RESULTS = 8;

const AUDIENCE_LABELS: Record<string, string> = {
  public: "Public",
  members: "Adhérents",
  friends: "Amis uniquement",
};

const AUDIENCE_HINTS: Record<string, string> = {
  public: "Visible par tout le monde, même les visiteurs non connectés.",
  members: "Visible uniquement par les membres connectés au site.",
  friends: "Visible uniquement par tes amis.",
};

type SaveState = { kind: "idle" } | { kind: "saving" } | { kind: "saved" } | { kind: "error"; message: string };

type FormValues = {
  displayName: string;
  bio: string;
  tagline: string;
  pronouns: string;
  bannerPreset: string;
  avatarFrame: string | null;
  audience: string;
  socialLinks: EditableSocialLink[];
  favorites: EditableFavoriteGame[];
  showcase: string[];
};

// Stable serialization of the *savable* shape, used both to detect unsaved changes and to re-baseline
// after a successful save. Trimming + dropping empty links means whitespace and blank rows never mark
// the form dirty.
function serialize(v: FormValues): string {
  return JSON.stringify({
    displayName: v.displayName.trim(),
    bio: v.bio.trim(),
    tagline: v.tagline.trim(),
    pronouns: v.pronouns.trim(),
    bannerPreset: v.bannerPreset,
    avatarFrame: v.avatarFrame,
    audience: v.audience,
    socialLinks: v.socialLinks
      .filter((l) => l.url.trim() !== "")
      .map((l) => ({ label: l.label.trim(), url: l.url.trim() })),
    favoriteGameIds: v.favorites.map((g) => g.id),
    showcase: v.showcase,
  });
}

export function CommunityProfileCustomizationForm() {
  const [loading, setLoading] = useState(true);
  const [slug, setSlug] = useState<string | null>(null);
  const [accountName, setAccountName] = useState("");
  const [displayName, setDisplayName] = useState("");
  const [bio, setBio] = useState("");
  const [tagline, setTagline] = useState("");
  const [pronouns, setPronouns] = useState("");
  const [bannerPreset, setBannerPreset] = useState<string>("default");
  const [avatarFrame, setAvatarFrame] = useState<string | null>(null);
  // Avatar upload is applied immediately (not through the save bar), so it lives outside `values`.
  const [avatarUrl, setAvatarUrl] = useState<string | null>(null);
  const [hasCustomAvatar, setHasCustomAvatar] = useState(false);
  const [avatar, setAvatar] = useState<SaveState>({ kind: "idle" });
  const avatarInputRef = useRef<HTMLInputElement | null>(null);
  const [audience, setAudience] = useState<string>("members");
  const [socialLinks, setSocialLinks] = useState<EditableSocialLink[]>([]);
  const [favorites, setFavorites] = useState<EditableFavoriteGame[]>([]);
  const [showcase, setShowcase] = useState<string[]>([]);
  const [catalog, setCatalog] = useState<PublicGame[]>([]);
  const [save, setSave] = useState<SaveState>({ kind: "idle" });
  const [baseline, setBaseline] = useState<string>("");

  const values: FormValues = useMemo(
    () => ({ displayName, bio, tagline, pronouns, bannerPreset, avatarFrame, audience, socialLinks, favorites, showcase }),
    [displayName, bio, tagline, pronouns, bannerPreset, avatarFrame, audience, socialLinks, favorites, showcase],
  );
  const serialized = useMemo(() => serialize(values), [values]);
  const isDirty = baseline !== "" && serialized !== baseline;

  function hydrate(profile: MyCommunityProfile): void {
    // A frame key that no longer exists (retired) resets to "none" so saving can't 422 on it.
    const frame = profile.avatarFrame && AVATAR_FRAME_KEYS.includes(profile.avatarFrame) ? profile.avatarFrame : null;
    setSlug(profile.slug);
    setAccountName(profile.accountName ?? "");
    setDisplayName(profile.displayName ?? "");
    setBio(profile.bio ?? "");
    setTagline(profile.tagline ?? "");
    setPronouns(profile.pronouns ?? "");
    setBannerPreset(profile.bannerPreset);
    setAvatarFrame(frame);
    setAvatarUrl(profile.avatarUrl);
    setHasCustomAvatar(profile.hasCustomAvatar);
    setAudience(profile.audience);
    setSocialLinks(profile.socialLinks);
    setFavorites(profile.favoriteGames);
    setShowcase(profile.showcaseLayout);
    setBaseline(
      serialize({
        displayName: profile.displayName ?? "",
        bio: profile.bio ?? "",
        tagline: profile.tagline ?? "",
        pronouns: profile.pronouns ?? "",
        bannerPreset: profile.bannerPreset,
        avatarFrame: frame,
        audience: profile.audience,
        socialLinks: profile.socialLinks,
        favorites: profile.favoriteGames,
        showcase: profile.showcaseLayout,
      }),
    );
  }

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const [profile, games] = await Promise.all([fetchMyCommunityProfile(), getAllPublicGames()]);
      if (cancelled) return;
      setCatalog(games);
      if (profile) hydrate(profile);
      setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  // Guard against losing edits on a hard navigation / refresh.
  useEffect(() => {
    if (!isDirty) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [isDirty]);

  async function handleSave() {
    setSave({ kind: "saving" });
    const result = await updateMyCommunityProfile({
      displayName: displayName.trim() === "" ? null : displayName.trim(),
      bio: bio.trim() === "" ? null : bio.trim(),
      tagline: tagline.trim() === "" ? null : tagline.trim(),
      pronouns: pronouns.trim() === "" ? null : pronouns.trim(),
      bannerPreset,
      avatarFrame,
      audience,
      socialLinks: socialLinks.filter((l) => l.url.trim() !== ""),
      favoriteGameIds: favorites.map((g) => g.id),
      showcaseLayout: showcase,
    });
    if (result?.ok) {
      hydrate(result.profile);
      setSave({ kind: "saved" });
    } else if (result) {
      setSave({ kind: "error", message: "Certains champs sont invalides (liens, longueurs…)." });
    } else {
      setSave({ kind: "error", message: "Impossible de sauvegarder le profil." });
    }
  }

  async function handleAvatarPick(file: File) {
    setAvatar({ kind: "saving" });
    const result = await uploadCommunityAvatar(file);
    if (result) {
      setAvatarUrl(result.avatarUrl);
      setHasCustomAvatar(true);
      setAvatar({ kind: "saved" });
    } else {
      setAvatar({ kind: "error", message: "Image refusée (format JPEG/PNG/WebP, 5 Mo max) ou envoi impossible." });
    }
    if (avatarInputRef.current) avatarInputRef.current.value = "";
  }

  async function handleAvatarRemove() {
    setAvatar({ kind: "saving" });
    const result = await removeCommunityAvatar();
    if (result) {
      setAvatarUrl(result.avatarUrl);
      setHasCustomAvatar(false);
      setAvatar({ kind: "idle" });
    } else {
      setAvatar({ kind: "error", message: "Impossible de retirer la photo." });
    }
  }

  function moveShowcase(index: number, direction: -1 | 1) {
    setShowcase((prev) => {
      const target = index + direction;
      if (target < 0 || target >= prev.length) return prev;
      const next = [...prev];
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  }

  if (loading) {
    return <CommunityLoadingSkeleton rows={5} />;
  }

  return (
    <div className="grid gap-5 pb-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">Personnalise ton profil public.</p>
        {slug ? (
          <Link className="text-sm font-medium text-accent-text hover:text-accent-text-hover" href={`/joueurs/${slug}`}>
            Voir mon profil →
          </Link>
        ) : null}
      </div>

      <Section title="Identité" description="Ce qui te présente en haut de ton profil.">
        <Field
          label="Pseudo affiché"
          counter={<CharCount value={displayName} max={MAX_DISPLAY_NAME} />}
          hint={
            displayName.trim() === ""
              ? `Laisse vide pour utiliser ton nom de compte${accountName !== "" ? ` (${accountName})` : ""}. Ton URL /joueurs/${slug ?? ""} ne change pas.`
              : "Affiché à la place de ton nom de compte. Ton URL de profil ne change pas."
          }
        >
          <input
            className={inputClass}
            maxLength={MAX_DISPLAY_NAME}
            onChange={(e) => setDisplayName(e.target.value)}
            placeholder={accountName !== "" ? accountName : "Ton pseudo affiché…"}
            type="text"
            value={displayName}
          />
        </Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="Accroche" counter={<CharCount value={tagline} max={MAX_TAGLINE} />}>
            <input
              className={inputClass}
              maxLength={MAX_TAGLINE}
              onChange={(e) => setTagline(e.target.value)}
              placeholder="Ta devise de joueur…"
              type="text"
              value={tagline}
            />
          </Field>
          <Field label="Pronoms" counter={<CharCount value={pronouns} max={MAX_PRONOUNS} />}>
            <input
              className={inputClass}
              maxLength={MAX_PRONOUNS}
              onChange={(e) => setPronouns(e.target.value)}
              placeholder="il/lui, elle, they…"
              type="text"
              value={pronouns}
            />
          </Field>
        </div>
        <Field label="À propos" counter={<CharCount value={bio} max={MAX_BIO} />}>
          <textarea
            className={`${inputClass} min-h-28`}
            maxLength={MAX_BIO}
            onChange={(e) => setBio(e.target.value)}
            placeholder="Parle de toi, de tes jeux préférés…"
            value={bio}
          />
        </Field>
      </Section>

      <Section title="Apparence" description="La bannière animée en tête de ton profil.">
        <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
          {BANNER_PRESETS.map((preset) => {
            const selected = bannerPreset === preset.key;
            return (
              <button
                aria-pressed={selected}
                className={`group overflow-hidden rounded-lg border text-left transition-colors ${
                  selected ? "border-accent ring-2 ring-accent/40" : "border-border hover:border-accent/60"
                }`}
                key={preset.key}
                onClick={() => setBannerPreset(preset.key)}
                type="button"
              >
                <ProfileBanner className="h-12 w-full" compact presetKey={preset.key} />
                <span className="flex items-center justify-between gap-1 px-2.5 py-1.5 text-xs font-medium text-foreground">
                  {preset.label}
                  {selected ? <Check aria-hidden className="size-3.5 text-accent-text" /> : null}
                </span>
              </button>
            );
          })}
        </div>
      </Section>

      <Section title="Photo de profil" description="Importe ta propre image (JPEG, PNG ou WebP, 5 Mo max). Sans image, un avatar par défaut est généré.">
        <div className="flex flex-wrap items-center gap-4">
          <ProfileAvatar avatarUrl={avatarUrl} frame={avatarFrame} name={displayName.trim() || accountName || slug || "?"} />
          <div className="grid gap-2">
            <div className="flex flex-wrap gap-2">
              <button
                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface px-3 py-1.5 text-sm font-medium text-foreground transition hover:bg-surface-hover disabled:opacity-50"
                disabled={avatar.kind === "saving"}
                onClick={() => avatarInputRef.current?.click()}
                type="button"
              >
                {avatar.kind === "saving" ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <ImagePlus aria-hidden className="size-4" />}
                {hasCustomAvatar ? "Changer la photo" : "Importer une photo"}
              </button>
              {hasCustomAvatar ? (
                <button
                  className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm font-medium text-muted-foreground transition hover:text-destructive disabled:opacity-50"
                  disabled={avatar.kind === "saving"}
                  onClick={() => void handleAvatarRemove()}
                  type="button"
                >
                  <Trash2 aria-hidden className="size-4" /> Retirer
                </button>
              ) : null}
            </div>
            {avatar.kind === "error" ? (
              <span className="flex items-center gap-1.5 text-xs text-destructive">
                <AlertCircle aria-hidden className="size-3.5" /> {avatar.message}
              </span>
            ) : null}
            <input
              accept="image/jpeg,image/png,image/webp"
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) void handleAvatarPick(file);
              }}
              ref={avatarInputRef}
              type="file"
            />
          </div>
        </div>
      </Section>

      <Section title="Cadre d'avatar" description="Un cadre décoratif (animé) autour de ton avatar.">
        <div className="grid gap-3">
          {FRAME_CATEGORIES.map((category) => (
            <div className="grid gap-1.5" key={category}>
              <span className="text-xs font-medium text-muted-foreground">{category}</span>
              <div className="flex flex-wrap gap-2.5">
                {category === FRAME_CATEGORIES[0] ? (
                  <FrameSwatch frameKey={null} label="Aucun" onPick={setAvatarFrame} selected={null === avatarFrame} />
                ) : null}
                {AVATAR_FRAMES.filter((f) => f.category === category).map((f) => (
                  <FrameSwatch frameKey={f.key} key={f.key} label={f.label} onPick={setAvatarFrame} selected={avatarFrame === f.key} />
                ))}
              </div>
            </div>
          ))}
        </div>
      </Section>

      <Section title="Confidentialité" description="Qui peut voir la partie personnalisée de ton profil.">
        <Field label="Audience" hint={AUDIENCE_HINTS[audience]}>
          <select className={inputClass} onChange={(e) => setAudience(e.target.value)} value={audience}>
            {AUDIENCES.map((value) => (
              <option key={value} value={value}>
                {AUDIENCE_LABELS[value] ?? value}
              </option>
            ))}
          </select>
        </Field>
      </Section>

      <Section
        title="Vitrine"
        description="Les blocs affichés sur ton profil, et leur ordre. Le bloc « Jeux favoris » contient les jeux que tu choisis ici."
      >
        {showcase.length > 0 ? (
          <ul className="grid gap-2" role="list">
            {showcase.map((widget, index) => {
              const label = SHOWCASE_WIDGET_LABELS[widget] ?? widget;
              const isFavorites = widget === "favorite_games";
              return (
                <li className="grid gap-2 rounded-lg border border-border bg-background px-3 py-2.5" key={widget}>
                  <div className="flex items-center justify-between gap-2">
                    <span className="text-sm font-medium text-foreground">
                      {label}
                      {isFavorites ? (
                        <span className="font-normal text-muted-foreground">
                          {" "}
                          ({favorites.length}/{MAX_FAVORITES})
                        </span>
                      ) : null}
                    </span>
                    <div className="flex items-center gap-1">
                      <IconBtn label="Monter" disabled={index === 0} onClick={() => moveShowcase(index, -1)}>
                        <ArrowUp aria-hidden className="size-3.5" />
                      </IconBtn>
                      <IconBtn label="Descendre" disabled={index === showcase.length - 1} onClick={() => moveShowcase(index, 1)}>
                        <ArrowDown aria-hidden className="size-3.5" />
                      </IconBtn>
                      <IconBtn danger label={`Retirer ${label}`} onClick={() => setShowcase((prev) => prev.filter((w) => w !== widget))}>
                        <X aria-hidden className="size-3.5" />
                      </IconBtn>
                    </div>
                  </div>
                  {isFavorites ? <FavoritesEditor catalog={catalog} favorites={favorites} setFavorites={setFavorites} /> : null}
                </li>
              );
            })}
          </ul>
        ) : (
          <p className="text-xs text-muted-foreground">Aucun bloc - ajoutes-en pour mettre ton profil en avant.</p>
        )}
        {SHOWCASE_WIDGETS.some((w) => !showcase.includes(w)) ? (
          <select
            aria-label="Ajouter un bloc de vitrine"
            className={`${inputClass} sm:w-72`}
            onChange={(e) => {
              if (e.target.value) setShowcase((prev) => [...prev, e.target.value]);
            }}
            value=""
          >
            <option disabled value="">
              + Ajouter un bloc…
            </option>
            {SHOWCASE_WIDGETS.filter((w) => !showcase.includes(w)).map((w) => (
              <option key={w} value={w}>
                {SHOWCASE_WIDGET_LABELS[w] ?? w}
              </option>
            ))}
          </select>
        ) : null}
      </Section>

      <Section title={`Liens (${socialLinks.length}/${MAX_SOCIAL_LINKS})`} description="Choisis une plateforme et colle ton lien.">
        <div className="grid gap-2">
          {socialLinks.map((link, index) => (
            <SocialLinkRow
              key={index}
              link={link}
              onChange={(patch) => updateLink(setSocialLinks, index, patch)}
              onRemove={() => setSocialLinks((prev) => prev.filter((_, i) => i !== index))}
            />
          ))}
          {socialLinks.length < MAX_SOCIAL_LINKS ? (
            <button
              className="inline-flex min-h-9 w-fit items-center gap-1.5 rounded-lg border border-dashed border-border px-3 text-sm text-muted-foreground hover:border-accent hover:text-foreground"
              onClick={() => setSocialLinks((prev) => [...prev, { label: "website", url: "" }])}
              type="button"
            >
              <Plus aria-hidden className="size-3.5" />
              Ajouter un lien
            </button>
          ) : null}
        </div>
      </Section>

      {/* Sticky save bar */}
      <div className="sticky bottom-0 z-10 -mx-1 flex flex-wrap items-center justify-between gap-3 border-t border-border bg-surface/95 px-1 py-3 backdrop-blur">
        <SaveStatus dirty={isDirty} save={save} />
        <button
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
          disabled={save.kind === "saving" || !isDirty}
          onClick={() => void handleSave()}
          type="button"
        >
          {save.kind === "saving" ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          {save.kind === "saving" ? "Sauvegarde…" : "Sauvegarder"}
        </button>
      </div>
    </div>
  );
}

// ── Sub-components ───────────────────────────────────────────────────────────

function Section({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
  return (
    <section className="grid gap-3 rounded-xl border border-border bg-surface/40 p-4 sm:p-5">
      <div className="grid gap-0.5">
        <h3 className="font-heading text-base font-semibold text-foreground">{title}</h3>
        {description ? <p className="text-xs text-muted-foreground">{description}</p> : null}
      </div>
      {children}
    </section>
  );
}

function Field({
  label,
  counter,
  hint,
  children,
}: {
  label: string;
  counter?: React.ReactNode;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="grid gap-1.5" role="group" aria-label={label}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium text-foreground">{label}</span>
        {counter}
      </div>
      {children}
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}

function CharCount({ value, max }: { value: string; max: number }) {
  const n = value.length;
  const cls = n >= max ? "text-[color:var(--color-danger)]" : n >= max * 0.9 ? "text-amber-400" : "text-muted-foreground";
  return (
    <span className={`text-xs tabular-nums ${cls}`}>
      {n}/{max}
    </span>
  );
}

function SaveStatus({ dirty, save }: { dirty: boolean; save: SaveState }) {
  if (save.kind === "saving") {
    return (
      <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
        <Loader2 aria-hidden className="size-4 animate-spin" /> Sauvegarde…
      </span>
    );
  }
  if (save.kind === "error") {
    return (
      <span className="inline-flex items-center gap-1.5 text-sm text-[color:var(--color-danger)]">
        <AlertCircle aria-hidden className="size-4" /> {save.message}
      </span>
    );
  }
  if (save.kind === "saved" && !dirty) {
    return (
      <span className="inline-flex items-center gap-1.5 text-sm text-success">
        <CheckCircle aria-hidden className="size-4" /> Sauvegardé
      </span>
    );
  }
  if (dirty) {
    return (
      <span className="inline-flex items-center gap-1.5 text-sm text-amber-400">
        <span aria-hidden className="size-2 rounded-full bg-amber-400" /> Modifications non enregistrées
      </span>
    );
  }
  return <span className="text-sm text-muted-foreground">Tout est à jour.</span>;
}

function FrameSwatch({
  frameKey,
  label,
  selected,
  onPick,
}: {
  frameKey: string | null;
  label: string;
  selected: boolean;
  onPick: (key: string | null) => void;
}) {
  return (
    <button
      aria-label={label}
      aria-pressed={selected}
      className={`grid w-16 justify-items-center gap-1 rounded-lg border p-1.5 transition-colors ${
        selected ? "border-accent bg-accent/10" : "border-transparent hover:bg-surface-2"
      }`}
      onClick={() => onPick(frameKey)}
      title={label}
      type="button"
    >
      <AvatarFrame className="size-11" frameKey={frameKey}>
        <span className="flex h-full w-full items-center justify-center bg-surface-2 text-xs text-muted-foreground">
          {selected ? <Check aria-hidden className="size-4 text-accent-text" /> : "★"}
        </span>
      </AvatarFrame>
      <span className="w-full truncate text-center text-[11px] text-muted-foreground">{label}</span>
    </button>
  );
}

function SocialLinkRow({
  link,
  onChange,
  onRemove,
}: {
  link: EditableSocialLink;
  onChange: (patch: Partial<EditableSocialLink>) => void;
  onRemove: () => void;
}) {
  const type = resolveLinkType(link.label);
  const isOther = !isKnownLinkType(link.label);
  const Icon = type.icon;

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-border bg-background text-foreground">
        <Icon aria-hidden className="size-4" />
      </span>
      <select
        aria-label="Type de lien"
        className={`${inputClass} sm:w-40`}
        onChange={(e) => onChange({ label: e.target.value === OTHER_LINK_TYPE.key ? "" : e.target.value })}
        value={isOther ? OTHER_LINK_TYPE.key : type.key}
      >
        {LINK_TYPES.map((t) => (
          <option key={t.key} value={t.key}>
            {t.label}
          </option>
        ))}
        <option value={OTHER_LINK_TYPE.key}>{OTHER_LINK_TYPE.label}</option>
      </select>
      {isOther ? (
        <input
          aria-label="Nom du lien"
          className={`${inputClass} sm:w-32`}
          maxLength={40}
          onChange={(e) => onChange({ label: e.target.value })}
          placeholder="Nom"
          type="text"
          value={link.label}
        />
      ) : null}
      <input
        aria-label="URL du lien"
        className={`${inputClass} min-w-0 flex-1`}
        maxLength={300}
        onChange={(e) => onChange({ url: e.target.value })}
        placeholder={type.placeholder}
        type="url"
        value={link.url}
      />
      <IconBtn danger label="Supprimer le lien" onClick={onRemove}>
        <X aria-hidden className="size-4" />
      </IconBtn>
    </div>
  );
}

function FavoritesEditor({
  catalog,
  favorites,
  setFavorites,
}: {
  catalog: PublicGame[];
  favorites: EditableFavoriteGame[];
  setFavorites: React.Dispatch<React.SetStateAction<EditableFavoriteGame[]>>;
}) {
  return (
    <div className="grid gap-2 border-t border-border/60 pt-2.5">
      {favorites.length > 0 ? (
        <ul className="flex flex-wrap gap-2" role="list">
          {favorites.map((game) => (
            <li
              className="inline-flex min-h-8 items-center gap-1.5 rounded-full border border-accent bg-accent/15 py-0.5 pl-1.5 pr-1.5 text-sm font-medium text-accent-text"
              key={game.id}
            >
              <GameCover coverImageUrl={game.coverImageUrl} name={game.name} size="chip" />
              {game.name}
              <button
                aria-label={`Retirer ${game.name}`}
                className="inline-flex size-5 items-center justify-center rounded-full text-accent-text/70 hover:bg-accent/25 hover:text-accent-text"
                onClick={() => setFavorites((prev) => prev.filter((g) => g.id !== game.id))}
                type="button"
              >
                <X aria-hidden className="size-3" />
              </button>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-xs text-muted-foreground">Aucun jeu choisi - ajoute tes jeux favoris ci-dessous.</p>
      )}
      {favorites.length < MAX_FAVORITES ? (
        <FavoriteGamePicker
          catalog={catalog}
          chosenIds={new Set(favorites.map((g) => g.id))}
          onPick={(game) =>
            setFavorites((prev) => [
              ...prev,
              { id: game.id, name: game.name, slug: game.slug, coverImageUrl: game.coverImageUrl },
            ])
          }
        />
      ) : (
        <p className="text-xs text-muted-foreground">Limite atteinte ({MAX_FAVORITES}).</p>
      )}
    </div>
  );
}

function FavoriteGamePicker({
  catalog,
  chosenIds,
  onPick,
}: {
  catalog: PublicGame[];
  chosenIds: Set<string>;
  onPick: (game: PublicGame) => void;
}) {
  const [query, setQuery] = useState("");
  const q = query.trim().toLowerCase();
  const results = useMemo(() => {
    if (q === "") return [];
    return catalog.filter((g) => !chosenIds.has(g.id) && g.name.toLowerCase().includes(q)).slice(0, FAVORITE_RESULTS);
  }, [catalog, chosenIds, q]);

  return (
    <div className="grid gap-2">
      <div className="relative sm:max-w-sm">
        <Search aria-hidden className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <input
          aria-label="Rechercher un jeu à ajouter"
          className={`${inputClass} pl-9`}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Rechercher un jeu…"
          type="text"
          value={query}
        />
      </div>
      {q !== "" ? (
        results.length > 0 ? (
          <ul className="grid gap-1 rounded-lg border border-border bg-background p-1 sm:max-w-sm" role="list">
            {results.map((game) => (
              <li key={game.id}>
                <button
                  className="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-sm text-foreground hover:bg-surface"
                  onClick={() => {
                    onPick(game);
                    setQuery("");
                  }}
                  type="button"
                >
                  <GameCover coverImageUrl={game.coverImageUrl} name={game.name} size="row" />
                  <span className="min-w-0 flex-1 truncate">{game.name}</span>
                  <Plus aria-hidden className="size-4 shrink-0 text-accent-text" />
                </button>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-xs text-muted-foreground">Aucun jeu ne correspond à « {query} ».</p>
        )
      ) : null}
    </div>
  );
}

function GameCover({
  coverImageUrl,
  name,
  size,
}: {
  coverImageUrl: string | null;
  name: string;
  size: "chip" | "row";
}) {
  const box = size === "chip" ? "size-6" : "h-10 w-8";
  if (coverImageUrl) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- external IGDB cover
      <img
        alt=""
        className={`${box} shrink-0 rounded ${size === "chip" ? "rounded-full" : ""} object-cover object-top`}
        src={coverImageUrl}
      />
    );
  }
  return (
    <span
      className={`${box} flex shrink-0 items-center justify-center rounded ${
        size === "chip" ? "rounded-full" : ""
      } bg-surface text-[10px] font-semibold text-muted-foreground`}
    >
      {name.slice(0, 2).toUpperCase()}
    </span>
  );
}

function IconBtn({
  children,
  label,
  onClick,
  disabled = false,
  danger = false,
}: {
  children: React.ReactNode;
  label: string;
  onClick: () => void;
  disabled?: boolean;
  danger?: boolean;
}) {
  return (
    <button
      aria-label={label}
      className={`inline-flex size-8 items-center justify-center rounded text-muted-foreground disabled:opacity-30 ${
        danger
          ? "hover:bg-[color:var(--color-danger)]/10 hover:text-[color:var(--color-danger)]"
          : "hover:bg-surface hover:text-foreground"
      }`}
      disabled={disabled}
      onClick={onClick}
      title={label}
      type="button"
    >
      {children}
    </button>
  );
}

const inputClass =
  "min-h-10 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent";

function updateLink(
  setSocialLinks: React.Dispatch<React.SetStateAction<EditableSocialLink[]>>,
  index: number,
  patch: Partial<EditableSocialLink>,
): void {
  setSocialLinks((prev) => prev.map((link, i) => (i === index ? { ...link, ...patch } : link)));
}
