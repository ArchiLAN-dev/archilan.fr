"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { AlertCircle, ArrowDown, ArrowUp, Check, Crop, ImagePlus, Loader2, Plus, Search, Trash2, X } from "lucide-react";

import { MarkdownEditor } from "@/components/markdown/markdown-editor";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { getAllPublicGames, type PublicGame } from "@/features/games/public-games-api";
import { AVATAR_FRAME_KEYS, getAvatarFrame } from "./avatar-frames";
import { FramePickerDialog } from "./frame-picker-dialog";
import { FramePreview } from "./frame-preview";
import { CommunityLoadingSkeleton } from "./community-loading-skeleton";
import { BANNER_PRESETS } from "./banner-presets";
import { bannerLockReason, fetchProfileBannerCatalog, PROFILE_BANNER_CATALOG_QUERY_KEY } from "./profile-banner-catalog";
import { ProfileTitleBadge } from "./profile-title-badge";
import { NAME_COLORS } from "./name-colors";
import { fetchProfileTitleCatalog, PROFILE_TITLE_CATALOG_QUERY_KEY, titleLockReason, type ProfileTitle } from "./profile-title-catalog";
import { imageAccept, imageFormatsHint, imageUploadError } from "./custom-image-rules";
import { ImageFramingDialog, type FramingShape } from "./image-framing-dialog";
import { TitledName, type NameStyle } from "./titled-name";
import { CENTRED_FRAMING, type ImageFraming } from "./image-framing";
import { DEFAULT_BANNER_OVERLAY, ProfileBanner } from "./profile-banner";
import { isKnownLinkType, LINK_TYPES, OTHER_LINK_TYPE, resolveLinkType } from "./social-links";
import {
  AUDIENCES,
  DEFAULT_AUDIENCE,
  fetchMyCommunityProfile,
  removeCommunityAvatar,
  removeCommunityBanner,
  SHOWCASE_WIDGETS,
  SHOWCASE_WIDGET_LABELS,
  updateMyCommunityProfile,
  uploadCommunityAvatar,
  uploadCommunityBanner,
  type EditableFavoriteGame,
  type EditableSocialLink,
  type MyCommunityProfile,
} from "./community-profile-api";

const MAX_SOCIAL_LINKS = 5;
const MAX_FAVORITES = 6;

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

// Local row wrapper: gives each link row a stable list key (rows can be removed/re-added and
// label/url are editable). The rowId never leaves the form - it is stripped in handleSave.
type SocialLinkRowState = EditableSocialLink & { rowId: string };

type FormValues = {
  displayName: string;
  bio: string;
  tagline: string;
  pronouns: string;
  bannerPreset: string;
  bannerOverlay: number;
  avatarFraming: ImageFraming;
  bannerFraming: ImageFraming;
  titledName: boolean;
  title: string | null;
  nameColor: string | null;
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
    bannerOverlay: v.bannerOverlay,
    avatarFraming: v.avatarFraming,
    bannerFraming: v.bannerFraming,
    titledName: v.titledName,
    title: v.title,
    nameColor: v.nameColor,
    avatarFrame: v.avatarFrame,
    audience: v.audience,
    socialLinks: v.socialLinks
      .filter((l) => l.url.trim() !== "")
      .map((l) => ({ label: l.label.trim(), url: l.url.trim() })),
    favoriteGameIds: v.favorites.map((g) => g.id),
    showcase: v.showcase,
  });
}

type CommunityProfileCustomizationFormProps = {
  onDirtyChange?: (dirty: boolean) => void;
  registerSave?: (save: () => Promise<boolean>) => void;
};

export function CommunityProfileCustomizationForm({
  onDirtyChange,
  registerSave,
}: CommunityProfileCustomizationFormProps = {}) {
  const [slug, setSlug] = useState<string | null>(null);
  const [accountName, setAccountName] = useState("");
  const [displayName, setDisplayName] = useState("");
  const [bio, setBio] = useState("");
  const [tagline, setTagline] = useState("");
  const [pronouns, setPronouns] = useState("");
  const [bannerPreset, setBannerPreset] = useState<string>("default");
  // Story 30.41: how strongly the preset lies over the banner image; saved with the profile.
  const [bannerOverlay, setBannerOverlay] = useState<number>(DEFAULT_BANNER_OVERLAY);
  const [avatarFrame, setAvatarFrame] = useState<string | null>(null);
  // Story 30.46: the saved frame (a dot marks it in the picker), whether the picker window is open, and whether the
  // account may pick a legendary (video) frame - admins only for a start.
  const [savedAvatarFrame, setSavedAvatarFrame] = useState<string | null>(null);
  const [framePickerOpen, setFramePickerOpen] = useState(false);
  const [legendaryAllowed, setLegendaryAllowed] = useState(false);
  // Story 41.7: the shop frames and banners this member bought.
  const [ownedFrames, setOwnedFrames] = useState<string[]>([]);
  const [memberFramesAllowed, setMemberFramesAllowed] = useState(false);
  const [ownedBanners, setOwnedBanners] = useState<string[]>([]);
  // Story 30.43: the framing of the uploaded photo and banner image, saved with the profile; the dialog that sets it.
  const [avatarFraming, setAvatarFraming] = useState<ImageFraming>(CENTRED_FRAMING);
  const [bannerFraming, setBannerFraming] = useState<ImageFraming>(CENTRED_FRAMING);
  const [framingShape, setFramingShape] = useState<FramingShape | null>(null);
  // Story 30.44: the titled name - the owner's switch, and the style their status gives (null = none).
  const [titledName, setTitledName] = useState(true);
  const [titledNameStyle, setTitledNameStyle] = useState<NameStyle | null>(null);
  // Story 41.22: the title worn under the name, and the shop titles bought.
  const [profileTitle, setProfileTitle] = useState<string | null>(null);
  const [ownedTitles, setOwnedTitles] = useState<string[]>([]);
  // Story 41.23: the colour worn by the name, and the colours bought.
  const [nameColorKey, setNameColorKey] = useState<string | null>(null);
  const [ownedColors, setOwnedColors] = useState<string[]>([]);
  const { data: titleCatalog = [] } = useQuery({ queryKey: PROFILE_TITLE_CATALOG_QUERY_KEY, queryFn: fetchProfileTitleCatalog, staleTime: 5 * 60 * 1000, retry: false });
  // Avatar upload is applied immediately (not through the save bar), so it lives outside `values`.
  const [avatarUrl, setAvatarUrl] = useState<string | null>(null);
  const [hasCustomAvatar, setHasCustomAvatar] = useState(false);
  const [avatar, setAvatar] = useState<SaveState>({ kind: "idle" });
  const avatarInputRef = useRef<HTMLInputElement | null>(null);
  // Story 30.40: GIF avatar for an admin; banner image for members and admins, applied immediately too.
  const [avatarGifAllowed, setAvatarGifAllowed] = useState(false);
  const [bannerUpload, setBannerUpload] = useState<{ image: boolean; gif: boolean }>({ image: false, gif: false });
  const [bannerImageUrl, setBannerImageUrl] = useState<string | null>(null);
  const [bannerImage, setBannerImage] = useState<SaveState>({ kind: "idle" });
  const bannerInputRef = useRef<HTMLInputElement | null>(null);
  const [audience, setAudience] = useState<string>(DEFAULT_AUDIENCE);
  const [socialLinks, setSocialLinks] = useState<SocialLinkRowState[]>([]);
  const [favorites, setFavorites] = useState<EditableFavoriteGame[]>([]);
  const [showcase, setShowcase] = useState<string[]>([]);
  const [save, setSave] = useState<SaveState>({ kind: "idle" });
  const [baseline, setBaseline] = useState<string>("");
  // Whether the draft has been seeded from the server yet. Guards against re-seeding on a later query
  // update, which would wipe in-progress edits (#388).
  const hasHydratedRef = useRef(false);

  // Server state lives in TanStack Query (AC-ST2); the form fields above are the local draft copy.
  // Both fetchers resolve to a value (null / []) on error, never throw - retry off like the old effect.
  const { data: profileData, isLoading: loadingProfile } = useQuery({
    queryKey: ["community-my-profile"],
    queryFn: fetchMyCommunityProfile,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    // This is an edit surface: it must not refetch itself from under the user. The real protection
    // against clobbering a draft is the one-time hydration below (a refetch triggered elsewhere - the
    // nav avatar shares this query key - still updates the shared cache); this just avoids the form
    // itself kicking off needless focus refetches.
    refetchOnWindowFocus: false,
  });
  const { data: catalogData, isLoading: loadingCatalog } = useQuery({
    queryKey: ["public-games", "all"],
    queryFn: getAllPublicGames,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const catalog: PublicGame[] = catalogData ?? [];
  const loading = loadingProfile || loadingCatalog;
  // Story 41.11: the admin catalog of banners (new ones, and the access and order of every banner).
  const { data: bannerCatalog = [] } = useQuery({
    queryKey: PROFILE_BANNER_CATALOG_QUERY_KEY,
    queryFn: fetchProfileBannerCatalog,
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
  const bannerChoices = useMemo(() => {
    // The catalog's order once loaded (retired presets left out); the presets alone before.
    if (bannerCatalog.length === 0) return BANNER_PRESETS.map((preset) => ({ key: preset.key, label: preset.label, shop: true === preset.shop, media: null }));
    return bannerCatalog.map((entry) => ({
      key: entry.key,
      label: entry.label,
      shop: true === BANNER_PRESETS.find((preset) => preset.key === entry.key)?.shop,
      media: entry.media,
    }));
  }, [bannerCatalog]);

  const values: FormValues = useMemo(
    () => ({ displayName, bio, tagline, pronouns, bannerPreset, bannerOverlay, avatarFraming, bannerFraming, titledName, title: profileTitle, nameColor: nameColorKey, avatarFrame, audience, socialLinks, favorites, showcase }),
    [displayName, bio, tagline, pronouns, bannerPreset, bannerOverlay, avatarFraming, bannerFraming, titledName, profileTitle, nameColorKey, avatarFrame, audience, socialLinks, favorites, showcase],
  );
  const serialized = useMemo(() => serialize(values), [values]);
  const isDirty = baseline !== "" && serialized !== baseline;

  const hydrate = useCallback((profile: MyCommunityProfile): void => {
    // A frame key that no longer exists (retired) resets to "none" so saving can't 422 on it.
    const frame = profile.avatarFrame && AVATAR_FRAME_KEYS.includes(profile.avatarFrame) ? profile.avatarFrame : null;
    setSlug(profile.slug);
    setAccountName(profile.accountName ?? "");
    setDisplayName(profile.displayName ?? "");
    setBio(profile.bio ?? "");
    setTagline(profile.tagline ?? "");
    setPronouns(profile.pronouns ?? "");
    setBannerPreset(profile.bannerPreset);
    setBannerOverlay(profile.bannerOverlay);
    setAvatarFraming(profile.avatarFraming);
    setBannerFraming(profile.bannerFraming);
    setTitledName(profile.titledName);
    setTitledNameStyle(profile.titledNameStyle);
    setProfileTitle(profile.title ?? null);
    setOwnedTitles(profile.ownedTitles ?? []);
    setNameColorKey(profile.nameColor ?? null);
    setOwnedColors(profile.ownedColors ?? []);
    setAvatarFrame(frame);
    setSavedAvatarFrame(frame);
    setLegendaryAllowed(profile.legendaryFramesAllowed);
    setOwnedFrames(profile.ownedFrames ?? []);
    setMemberFramesAllowed(profile.memberFramesAllowed ?? false);
    setOwnedBanners(profile.ownedBanners ?? []);
    // The editor previews the photo as it moves on the profile page (story 30.42).
    setAvatarUrl(profile.avatarAnimatedUrl ?? profile.avatarUrl);
    setHasCustomAvatar(profile.hasCustomAvatar);
    setAvatarGifAllowed(profile.avatarGifAllowed);
    setBannerUpload(profile.bannerUpload);
    setBannerImageUrl(profile.bannerImageUrl);
    setAudience(profile.audience);
    setSocialLinks(profile.socialLinks.map((l) => ({ ...l, rowId: crypto.randomUUID() })));
    setFavorites(profile.favoriteGames);
    setShowcase(profile.showcaseLayout);
    setBaseline(
      serialize({
        displayName: profile.displayName ?? "",
        bio: profile.bio ?? "",
        tagline: profile.tagline ?? "",
        pronouns: profile.pronouns ?? "",
        bannerPreset: profile.bannerPreset,
        bannerOverlay: profile.bannerOverlay,
        avatarFraming: profile.avatarFraming,
        bannerFraming: profile.bannerFraming,
        titledName: profile.titledName,
        title: profile.title ?? null,
        nameColor: profile.nameColor ?? null,
        avatarFrame: frame,
        audience: profile.audience,
        socialLinks: profile.socialLinks,
        favorites: profile.favoriteGames,
        showcase: profile.showcaseLayout,
      }),
    );
  }, []);

  // Seed the local draft from the server profile exactly once, on first load. It must NOT run again on
  // later query updates: a background refetch (including one triggered by the nav avatar, which shares
  // this query key) hands us a fresh object identity, and re-seeding then would overwrite whatever the
  // user is currently typing - the ~1 min silent data-loss bug (#388). Re-baselining after a save is
  // done directly in handleSave, not here. The draft cannot be derived during render (user edits
  // diverge from server state), hence the sanctioned setState-in-effect.
  useEffect(() => {
    if (profileData && !hasHydratedRef.current) {
      hasHydratedRef.current = true;
      hydrate(profileData);
    }
  }, [profileData, hydrate]);

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

  async function handleSave(): Promise<boolean> {
    setSave({ kind: "saving" });
    const result = await updateMyCommunityProfile({
      displayName: displayName.trim() === "" ? null : displayName.trim(),
      bio: bio.trim() === "" ? null : bio.trim(),
      tagline: tagline.trim() === "" ? null : tagline.trim(),
      pronouns: pronouns.trim() === "" ? null : pronouns.trim(),
      bannerPreset,
      bannerOverlay,
      avatarFraming,
      bannerFraming,
      titledName,
      title: profileTitle,
      nameColor: nameColorKey,
      avatarFrame,
      audience,
      socialLinks: socialLinks.filter((l) => l.url.trim() !== "").map((l) => ({ label: l.label, url: l.url })),
      favoriteGameIds: favorites.map((g) => g.id),
      showcaseLayout: showcase,
    });
    if (result?.ok) {
      hydrate(result.profile);
      setSave({ kind: "saved" });
      return true;
    }
    setSave({
      kind: "error",
      message: result
        ? "Certains champs sont invalides (liens, longueurs…)."
        : "Impossible de sauvegarder le profil.",
    });
    return false;
  }

  // Surface dirty state + the save handler to the shared save bar (parent orchestrator).
  useEffect(() => {
    onDirtyChange?.(isDirty);
  }, [isDirty, onDirtyChange]);

  useEffect(() => {
    registerSave?.(handleSave);
  });

  // A new or removed image comes back centred from the API: the draft and the baseline follow, so the form
  // does not turn dirty for it.
  function recentre(field: "avatarFraming" | "bannerFraming") {
    (field === "avatarFraming" ? setAvatarFraming : setBannerFraming)(CENTRED_FRAMING);
    setBaseline((prev) => (prev === "" ? prev : JSON.stringify({ ...(JSON.parse(prev) as object), [field]: CENTRED_FRAMING })));
  }

  async function handleAvatarPick(file: File) {
    setAvatar({ kind: "saving" });
    const result = await uploadCommunityAvatar(file);
    if (result.ok) {
      setAvatarUrl(result.url);
      setHasCustomAvatar(true);
      recentre("avatarFraming");
      setAvatar({ kind: "saved" });
      setFramingShape("avatar");
    } else {
      setAvatar({ kind: "error", message: imageUploadError(result.code) });
    }
    if (avatarInputRef.current) avatarInputRef.current.value = "";
  }

  async function handleBannerPick(file: File) {
    setBannerImage({ kind: "saving" });
    const result = await uploadCommunityBanner(file);
    if (result.ok) {
      setBannerImageUrl(result.url);
      recentre("bannerFraming");
      setBannerImage({ kind: "saved" });
      setFramingShape("banner");
    } else {
      setBannerImage({ kind: "error", message: imageUploadError(result.code) });
    }
    if (bannerInputRef.current) bannerInputRef.current.value = "";
  }

  async function handleBannerRemove() {
    setBannerImage({ kind: "saving" });
    if (await removeCommunityBanner()) {
      setBannerImageUrl(null);
      recentre("bannerFraming");
      setBannerImage({ kind: "idle" });
    } else {
      setBannerImage({ kind: "error", message: "Impossible de retirer l'image." });
    }
  }

  async function handleAvatarRemove() {
    setAvatar({ kind: "saving" });
    const result = await removeCommunityAvatar();
    if (result) {
      setAvatarUrl(result.avatarUrl);
      setHasCustomAvatar(false);
      recentre("avatarFraming");
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

  const framingImage = framingShape === "avatar" ? (hasCustomAvatar ? avatarUrl : null) : framingShape === "banner" ? bannerImageUrl : null;

  return (
    <div className="grid gap-5 pb-2">
      {framingShape !== null && framingImage !== null ? (
        <ImageFramingDialog
          framing={framingShape === "avatar" ? avatarFraming : bannerFraming}
          imageUrl={framingImage}
          onConfirm={framingShape === "avatar" ? setAvatarFraming : setBannerFraming}
          onOpenChange={(open) => {
            if (!open) setFramingShape(null);
          }}
          open
          shape={framingShape}
        />
      ) : null}
      <Section title="Apparence" description="La bannière animée en tête de ton profil.">
        <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
          {bannerChoices.map((choice) => {
            const selected = bannerPreset === choice.key;
            // Stories 41.7 and 41.11: each banner is locked by its own access (a shop one until bought). The one
            // already shown stays selectable.
            const reason = selected
              ? null
              : bannerLockReason(choice, bannerCatalog.find((entry) => entry.key === choice.key)?.access, {
                  admin: legendaryAllowed,
                  member: memberFramesAllowed,
                  owned: ownedBanners,
                });
            return (
              <button
                aria-pressed={selected}
                disabled={reason !== null}
                title={reason ?? undefined}
                className={`group overflow-hidden rounded-lg border text-left transition-colors ${
                  selected ? "border-accent ring-2 ring-accent/40" : "border-border hover:border-accent/60"
                }`}
                key={choice.key}
                onClick={() => setBannerPreset(choice.key)}
                type="button"
              >
                <ProfileBanner className="h-12 w-full" compact media={choice.media} presetKey={choice.key} />
                <span className="flex items-center justify-between gap-1 px-2.5 py-1.5 text-xs font-medium text-foreground">
                  {choice.label}
                  {selected ? <Check aria-hidden className="size-3.5 text-accent-text" /> : null}
                </span>
              </button>
            );
          })}
        </div>
        {/* Story 41.12: the way to the banners on sale. */}
        <p className="text-xs text-muted-foreground">
          D&apos;autres bannières se gagnent avec tes pelles.{" "}
          <Link className="font-semibold text-accent-text underline-offset-2 hover:underline" href="/boutique">
            Voir la boutique
          </Link>
        </p>
      </Section>

      {bannerUpload.image ? (
        <Section
          title="Image de bannière"
          description={`Ta propre image en tête de profil, sous la bannière choisie ci-dessus. ${imageFormatsHint("banner", bannerUpload.gif)}`}
        >
          <div className="grid gap-3">
            <ProfileBanner
              className="h-40 w-full rounded-lg sm:h-56"
              framing={bannerFraming}
              imageUrl={bannerImageUrl}
              overlay={bannerOverlay}
              presetKey={bannerPreset}
            />
            {bannerImageUrl !== null ? (
              <label className="grid gap-1.5 text-sm">
                <span className="flex items-center justify-between font-medium text-foreground">
                  Intensité de la bannière
                  <span className="text-xs font-semibold text-muted-foreground">{bannerOverlay} %</span>
                </span>
                <input
                  aria-describedby="banner-overlay-help"
                  className="w-full accent-accent"
                  max={100}
                  min={0}
                  onChange={(e) => setBannerOverlay(Number(e.target.value))}
                  step={5}
                  type="range"
                  value={bannerOverlay}
                />
                <span className="text-xs text-muted-foreground" id="banner-overlay-help">
                  La bannière choisie ci-dessus se pose sur ton image : 0 % montre l&apos;image seule, 100 % la recouvre.
                </span>
              </label>
            ) : null}
            <div className="flex flex-wrap gap-2">
              <button
                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface px-3 py-1.5 text-sm font-medium text-foreground transition hover:bg-surface-hover disabled:opacity-50"
                disabled={bannerImage.kind === "saving"}
                onClick={() => bannerInputRef.current?.click()}
                type="button"
              >
                {bannerImage.kind === "saving" ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <ImagePlus aria-hidden className="size-4" />}
                {bannerImageUrl !== null ? "Changer l'image" : "Importer une image"}
              </button>
              {bannerImageUrl !== null ? (
                <button
                  className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm font-medium text-foreground transition hover:bg-surface-hover disabled:opacity-50"
                  disabled={bannerImage.kind === "saving"}
                  onClick={() => setFramingShape("banner")}
                  type="button"
                >
                  <Crop aria-hidden className="size-4" /> Recadrer
                </button>
              ) : null}
              {bannerImageUrl !== null ? (
                <button
                  className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm font-medium text-muted-foreground transition hover:text-destructive disabled:opacity-50"
                  disabled={bannerImage.kind === "saving"}
                  onClick={() => void handleBannerRemove()}
                  type="button"
                >
                  <Trash2 aria-hidden className="size-4" /> Retirer
                </button>
              ) : null}
            </div>
            {bannerImage.kind === "error" ? (
              <span className="flex items-center gap-1.5 text-xs text-destructive">
                <AlertCircle aria-hidden className="size-3.5" /> {bannerImage.message}
              </span>
            ) : null}
            <input
              accept={imageAccept(bannerUpload.gif)}
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) void handleBannerPick(file);
              }}
              ref={bannerInputRef}
              type="file"
            />
          </div>
        </Section>
      ) : null}

      <Section
        title="Photo de profil et cadre"
        description={`Importe ta propre image (${imageFormatsHint("avatar", avatarGifAllowed)}) Sans image, un avatar par défaut est généré. Un cadre choisi ne s'enregistre qu'avec « Enregistrer ».`}
      >
        <div className="grid gap-6 sm:grid-cols-[auto_minmax(0,1fr)] sm:items-start">
          <FramePreview
            avatarUrl={avatarUrl}
            frame={avatarFrame}
            framing={hasCustomAvatar ? avatarFraming : null}
            banner={{ presetKey: bannerPreset, imageUrl: bannerImageUrl, framing: bannerFraming, overlay: bannerOverlay }}
            name={displayName.trim() || accountName || slug || "?"}
          />
          <div className="grid gap-5">
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
                    className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm font-medium text-foreground transition hover:bg-surface-hover disabled:opacity-50"
                    disabled={avatar.kind === "saving"}
                    onClick={() => setFramingShape("avatar")}
                    type="button"
                  >
                    <Crop aria-hidden className="size-4" /> Recadrer
                  </button>
                ) : null}
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
                accept={imageAccept(avatarGifAllowed)}
                className="hidden"
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) void handleAvatarPick(file);
                }}
                ref={avatarInputRef}
                type="file"
              />
            </div>
            <div className="flex flex-wrap items-center gap-3">
              <span className="text-sm text-muted-foreground">
                Cadre : <span className="font-medium text-foreground">{getAvatarFrame(avatarFrame)?.label ?? "Aucun"}</span>
              </span>
              <button
                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-surface px-3 py-1.5 text-sm font-medium text-foreground transition hover:bg-surface-hover"
                onClick={() => setFramePickerOpen(true)}
                type="button"
              >
                Changer le cadre
              </button>
            </div>
          </div>
        </div>
      </Section>

      <FramePickerDialog
        avatar={{ avatarUrl, name: displayName.trim() || accountName || slug || "?", framing: hasCustomAvatar ? avatarFraming : null }}
        banner={{ presetKey: bannerPreset, imageUrl: bannerImageUrl, framing: bannerFraming, overlay: bannerOverlay }}
        current={avatarFrame}
        legendaryAllowed={legendaryAllowed}
        memberAllowed={memberFramesAllowed}
        ownedFrames={ownedFrames}
        onApply={setAvatarFrame}
        onOpenChange={setFramePickerOpen}
        open={framePickerOpen}
        saved={savedAvatarFrame}
      />

      <Section title="Identité" description="Ce qui te présente en haut de ton profil.">
        <Field
          label="Pseudo affiché"
          counter={<CharCount value={displayName} max={MAX_DISPLAY_NAME} />}
          hint={
            displayName.trim() === ""
              ? `Laisse vide pour utiliser ton nom de compte${accountName !== "" ? ` (${accountName})` : ""}. Ton URL de profil se règle dans la section « URL de profil ».`
              : "Affiché à la place de ton nom de compte. Ton URL de profil se règle dans la section « URL de profil »."
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
        {titledNameStyle !== null ? (
          <div className="grid gap-2 rounded-lg border border-border bg-surface-2/40 p-3">
            <label className="flex items-center gap-2 text-sm font-medium text-foreground">
              <input
                checked={titledName}
                className="size-4 accent-accent"
                onChange={(e) => setTitledName(e.target.checked)}
                type="checkbox"
              />
              Pseudo à titre
            </label>
            {/* Room for the title, which hangs above the name. */}
            <span className="pt-5 font-heading text-xl font-bold text-foreground">
              <TitledName style={titledName ? titledNameStyle : null} variant="profile">{displayName.trim() || accountName || slug || "Ton pseudo"}</TitledName>
            </span>
            <span className="text-xs text-muted-foreground">
              {titledNameStyle === "legendary"
                ? "Titre « Administrateur », couleurs légendaires : en grand sur ton profil, avec ta couronne sur tes cartes dans la communauté."
                : "Titre « Adhérent ArchiLAN », en platine : en grand sur ton profil, avec ton étoile sur tes cartes dans la communauté."}
            </span>
          </div>
        ) : null}
        <NameColorField
          name={displayName.trim() || accountName || slug || "Ton pseudo"}
          onChange={setNameColorKey}
          owned={ownedColors}
          rarityWins={titledNameStyle !== null && titledName}
          value={nameColorKey}
        />
        <ProfileTitleField
          catalog={titleCatalog}
          onChange={setProfileTitle}
          rights={{ admin: legendaryAllowed, member: memberFramesAllowed, owned: ownedTitles }}
          value={profileTitle}
        />
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
          <MarkdownEditor
            maxLength={MAX_BIO}
            onChange={setBio}
            placeholder="Parle de toi, de tes jeux préférés…"
            rows={5}
            untrusted
            value={bio}
          />
        </Field>
      </Section>

      <Section title={`Liens (${socialLinks.length}/${MAX_SOCIAL_LINKS})`} description="Choisis une plateforme et colle ton lien.">
        <div className="grid gap-2">
          {socialLinks.map((link, index) => (
            <SocialLinkRow
              key={link.rowId}
              link={link}
              onChange={(patch) => updateLink(setSocialLinks, index, patch)}
              onRemove={() => setSocialLinks((prev) => prev.filter((_, i) => i !== index))}
            />
          ))}
          {socialLinks.length < MAX_SOCIAL_LINKS ? (
            <button
              className="inline-flex min-h-9 w-fit items-center gap-1.5 rounded-lg border border-dashed border-border px-3 text-sm text-muted-foreground hover:border-accent hover:text-foreground"
              onClick={() => setSocialLinks((prev) => [...prev, { label: "website", url: "", rowId: crypto.randomUUID() }])}
              type="button"
            >
              <Plus aria-hidden className="size-3.5" />
              Ajouter un lien
            </button>
          ) : null}
        </div>
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

      {save.kind === "error" ? (
        <p className="flex items-center gap-1.5 text-sm text-[color:var(--color-danger)]" role="alert">
          <AlertCircle aria-hidden className="size-4" /> {save.message}
        </p>
      ) : null}
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
  setSocialLinks: React.Dispatch<React.SetStateAction<SocialLinkRowState[]>>,
  index: number,
  patch: Partial<EditableSocialLink>,
): void {
  setSocialLinks((prev) => prev.map((link, i) => (i === index ? { ...link, ...patch } : link)));
}

/**
 * Story 41.22: the title worn under the name - any the member may wear, the others shown locked with why (bought in
 * the shop, members or admins only). A title the admins retired stays shown until another is picked.
 */
export function ProfileTitleField({
  catalog,
  value,
  rights,
  onChange,
}: {
  catalog: ProfileTitle[];
  value: string | null;
  rights: { admin: boolean; member: boolean; owned: readonly string[] };
  onChange: (key: string | null) => void;
}) {
  const current = catalog.find((title) => title.key === value) ?? null;
  const locked = catalog.filter((title) => titleLockReason(title.access, title.key, rights) !== null);

  return (
    <div className="grid gap-2 rounded-lg border border-border bg-surface-2/40 p-3">
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Titre de profil</span>
        <select className="min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground" onChange={(e) => onChange(e.target.value === "" ? null : e.target.value)} value={value ?? ""}>
          <option value="">Aucun</option>
          {value !== null && current === null ? <option value={value}>Titre retiré</option> : null}
          {catalog.map((title) => {
            const reason = titleLockReason(title.access, title.key, rights);
            return (
              <option disabled={reason !== null && title.key !== value} key={title.key} value={title.key}>
                {reason !== null && title.key !== value ? `${title.label} - ${reason}` : title.label}
              </option>
            );
          })}
        </select>
      </label>
      {current ? (
        <span>
          <ProfileTitleBadge label={current.label} />
        </span>
      ) : null}
      <span className="text-xs text-muted-foreground">
        Affiché sous ton pseudo sur ton profil.
        {locked.some((title) => title.access === "shop") ? (
          <>
            {" "}
            D&apos;autres titres s&apos;achètent en{" "}
            <Link className="text-accent-text hover:underline" href="/boutique">
              boutique
            </Link>
            .
          </>
        ) : null}
      </span>
    </div>
  );
}

/**
 * Story 41.23: the colour of the name, among those bought (the others shown locked, to buy in the shop). The rarity
 * colour of a member or an admin comes first while « Pseudo à titre » is on: the field says so.
 */
export function NameColorField({
  value,
  owned,
  name,
  rarityWins,
  onChange,
}: {
  value: string | null;
  owned: readonly string[];
  name: string;
  rarityWins: boolean;
  onChange: (key: string | null) => void;
}) {
  const current = NAME_COLORS.find((color) => color.key === value) ?? null;

  return (
    <fieldset className="grid gap-2 rounded-lg border border-border bg-surface-2/40 p-3">
      <legend className="px-1 text-sm font-medium text-foreground">Couleur du pseudo</legend>
      <div className="flex flex-wrap items-center gap-2">
        <button
          aria-pressed={value === null}
          className={`rounded-full border px-3 py-1 text-xs ${value === null ? "border-accent text-foreground" : "border-border text-muted-foreground hover:border-accent/50"}`}
          onClick={() => onChange(null)}
          type="button"
        >
          Aucune
        </button>
        {NAME_COLORS.map((color) => {
          const mine = owned.includes(color.key) || color.key === value;
          return (
            <button
              aria-label={mine ? color.label : `${color.label} - à acheter en boutique`}
              aria-pressed={color.key === value}
              className={`grid size-8 place-items-center rounded-full border-2 transition-transform ${color.key === value ? "scale-110 border-foreground" : "border-transparent"} ${mine ? "hover:scale-110" : "cursor-not-allowed opacity-30"}`}
              disabled={!mine}
              key={color.key}
              onClick={() => onChange(color.key)}
              style={{ backgroundColor: color.hex }}
              title={mine ? color.label : `${color.label} - à acheter en boutique`}
              type="button"
            />
          );
        })}
      </div>
      {current ? (
        <span className="font-heading text-lg font-bold" style={{ color: current.hex }}>
          {name}
        </span>
      ) : null}
      <span className="text-xs text-muted-foreground">
        {rarityWins
          ? "Ta couleur de rareté passe avant tant que « Pseudo à titre » est coché : décoche-le pour porter cette couleur."
          : "Sur ton profil et partout où ton pseudo apparaît."}{" "}
        {owned.length < NAME_COLORS.length ? (
          <>
            Les autres couleurs s&apos;achètent en{" "}
            <Link className="text-accent-text hover:underline" href="/boutique">
              boutique
            </Link>
            .
          </>
        ) : null}
      </span>
    </fieldset>
  );
}
