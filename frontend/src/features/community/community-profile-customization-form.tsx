"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { AlertCircle, CheckCircle, Loader2, Plus, X } from "lucide-react";

import { getAllPublicGames, type PublicGame } from "@/features/games/public-games-api";
import {
  AUDIENCES,
  BANNER_PRESETS,
  fetchMyCommunityProfile,
  updateMyCommunityProfile,
  type EditableFavoriteGame,
  type EditableSocialLink,
} from "./community-profile-api";

const MAX_SOCIAL_LINKS = 5;
const MAX_FAVORITES = 6;

const AUDIENCE_LABELS: Record<string, string> = {
  public: "Public — tout le monde",
  members: "Adhérents — membres connectés",
  friends: "Amis uniquement",
};

type SaveState = { kind: "idle" } | { kind: "saving" } | { kind: "saved" } | { kind: "error"; message: string };

export function CommunityProfileCustomizationForm() {
  const [loading, setLoading] = useState(true);
  const [slug, setSlug] = useState<string | null>(null);
  const [bio, setBio] = useState("");
  const [tagline, setTagline] = useState("");
  const [pronouns, setPronouns] = useState("");
  const [bannerPreset, setBannerPreset] = useState<string>("default");
  const [audience, setAudience] = useState<string>("members");
  const [socialLinks, setSocialLinks] = useState<EditableSocialLink[]>([]);
  const [favorites, setFavorites] = useState<EditableFavoriteGame[]>([]);
  const [catalog, setCatalog] = useState<PublicGame[]>([]);
  const [save, setSave] = useState<SaveState>({ kind: "idle" });

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      const [profile, games] = await Promise.all([fetchMyCommunityProfile(), getAllPublicGames()]);
      if (cancelled) return;
      setCatalog(games);
      if (profile) {
        setSlug(profile.slug);
        setBio(profile.bio ?? "");
        setTagline(profile.tagline ?? "");
        setPronouns(profile.pronouns ?? "");
        setBannerPreset(profile.bannerPreset);
        setAudience(profile.audience);
        setSocialLinks(profile.socialLinks);
        setFavorites(profile.favoriteGames);
      }
      setLoading(false);
    })();
    return () => { cancelled = true; };
  }, []);

  async function handleSave() {
    setSave({ kind: "saving" });
    const result = await updateMyCommunityProfile({
      bio: bio.trim() === "" ? null : bio.trim(),
      tagline: tagline.trim() === "" ? null : tagline.trim(),
      pronouns: pronouns.trim() === "" ? null : pronouns.trim(),
      bannerPreset,
      audience,
      socialLinks: socialLinks.filter((l) => l.url.trim() !== ""),
      favoriteGameIds: favorites.map((g) => g.id),
    });
    if (result?.ok) {
      setFavorites(result.profile.favoriteGames);
      setSave({ kind: "saved" });
    } else if (result) {
      setSave({ kind: "error", message: "Certains champs sont invalides (liens, longueurs…)." });
    } else {
      setSave({ kind: "error", message: "Impossible de sauvegarder le profil." });
    }
  }

  if (loading) {
    return (
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        <Loader2 aria-hidden className="size-4 animate-spin" />
        Chargement du profil…
      </div>
    );
  }

  const favoriteIds = new Set(favorites.map((g) => g.id));
  const addableGames = catalog.filter((g) => !favoriteIds.has(g.id));

  return (
    <div className="grid gap-6">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted-foreground">
          Personnalise ton profil public.
        </p>
        {slug ? (
          <Link className="text-sm text-accent-text hover:text-accent-text-hover" href={`/joueurs/${slug}`}>
            Voir mon profil →
          </Link>
        ) : null}
      </div>

      {/* Tagline + pronouns */}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Accroche">
          <input
            className={inputClass}
            maxLength={120}
            onChange={(e) => { setTagline(e.target.value); setSave({ kind: "idle" }); }}
            placeholder="Ta devise de joueur…"
            type="text"
            value={tagline}
          />
        </Field>
        <Field label="Pronoms">
          <input
            className={inputClass}
            maxLength={40}
            onChange={(e) => { setPronouns(e.target.value); setSave({ kind: "idle" }); }}
            placeholder="il/lui, elle, they…"
            type="text"
            value={pronouns}
          />
        </Field>
      </div>

      {/* Bio */}
      <Field label="À propos">
        <textarea
          className={`${inputClass} min-h-28`}
          maxLength={2000}
          onChange={(e) => { setBio(e.target.value); setSave({ kind: "idle" }); }}
          placeholder="Parle de toi, de tes jeux préférés…"
          value={bio}
        />
      </Field>

      {/* Banner + audience */}
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Bannière">
          <select
            className={inputClass}
            onChange={(e) => { setBannerPreset(e.target.value); setSave({ kind: "idle" }); }}
            value={bannerPreset}
          >
            {BANNER_PRESETS.map((preset) => (
              <option key={preset} value={preset}>{preset}</option>
            ))}
          </select>
        </Field>
        <Field label="Qui peut voir ma personnalisation">
          <select
            className={inputClass}
            onChange={(e) => { setAudience(e.target.value); setSave({ kind: "idle" }); }}
            value={audience}
          >
            {AUDIENCES.map((value) => (
              <option key={value} value={value}>{AUDIENCE_LABELS[value] ?? value}</option>
            ))}
          </select>
        </Field>
      </div>

      {/* Favorite games */}
      <Field label={`Jeux favoris (${favorites.length}/${MAX_FAVORITES})`}>
        <div className="grid gap-2">
          {favorites.length > 0 ? (
            <ul className="flex flex-wrap gap-2" role="list">
              {favorites.map((game) => (
                <li
                  className="inline-flex min-h-9 items-center gap-1.5 rounded-full border border-accent bg-accent/15 pl-3 pr-1.5 text-sm font-medium text-accent-text"
                  key={game.id}
                >
                  {game.name}
                  <button
                    aria-label={`Retirer ${game.name}`}
                    className="inline-flex size-5 items-center justify-center rounded-full text-accent-text/70 hover:bg-accent/25 hover:text-accent-text"
                    onClick={() => { setFavorites((prev) => prev.filter((g) => g.id !== game.id)); setSave({ kind: "idle" }); }}
                    type="button"
                  >
                    <X aria-hidden className="size-3" />
                  </button>
                </li>
              ))}
            </ul>
          ) : null}
          {favorites.length < MAX_FAVORITES && addableGames.length > 0 ? (
            <select
              aria-label="Ajouter un jeu favori"
              className={`${inputClass} sm:w-72`}
              onChange={(e) => {
                const game = catalog.find((g) => g.id === e.target.value);
                if (game) {
                  setFavorites((prev) => [...prev, { id: game.id, name: game.name, slug: game.slug, coverImageUrl: game.coverImageUrl }]);
                  setSave({ kind: "idle" });
                }
              }}
              value=""
            >
              <option disabled value="">+ Ajouter un jeu…</option>
              {addableGames.map((game) => (
                <option key={game.id} value={game.id}>{game.name}</option>
              ))}
            </select>
          ) : null}
        </div>
      </Field>

      {/* Social links */}
      <Field label={`Liens (${socialLinks.length}/${MAX_SOCIAL_LINKS})`}>
        <div className="grid gap-2">
          {socialLinks.map((link, index) => (
            <div className="flex flex-wrap items-center gap-2" key={index}>
              <input
                aria-label="Libellé du lien"
                className={`${inputClass} sm:w-40`}
                maxLength={40}
                onChange={(e) => { updateLink(setSocialLinks, index, { label: e.target.value }); setSave({ kind: "idle" }); }}
                placeholder="Twitch, site…"
                type="text"
                value={link.label}
              />
              <input
                aria-label="URL du lien"
                className={`${inputClass} min-w-0 flex-1`}
                maxLength={300}
                onChange={(e) => { updateLink(setSocialLinks, index, { url: e.target.value }); setSave({ kind: "idle" }); }}
                placeholder="https://…"
                type="url"
                value={link.url}
              />
              <button
                aria-label="Supprimer le lien"
                className="inline-flex size-9 items-center justify-center rounded text-muted-foreground hover:bg-[color:var(--color-danger)]/10 hover:text-[color:var(--color-danger)]"
                onClick={() => { setSocialLinks((prev) => prev.filter((_, i) => i !== index)); setSave({ kind: "idle" }); }}
                type="button"
              >
                <X aria-hidden className="size-4" />
              </button>
            </div>
          ))}
          {socialLinks.length < MAX_SOCIAL_LINKS ? (
            <button
              className="inline-flex min-h-9 w-fit items-center gap-1.5 rounded border border-dashed border-border px-3 text-sm text-muted-foreground hover:border-accent hover:text-foreground"
              onClick={() => setSocialLinks((prev) => [...prev, { label: "", url: "" }])}
              type="button"
            >
              <Plus aria-hidden className="size-3.5" />
              Ajouter un lien
            </button>
          ) : null}
        </div>
      </Field>

      <div className="flex flex-wrap items-center gap-3">
        <button
          className="inline-flex min-h-11 items-center justify-center rounded bg-accent px-5 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-50"
          disabled={save.kind === "saving"}
          onClick={() => { void handleSave(); }}
          type="button"
        >
          {save.kind === "saving" ? "Sauvegarde…" : "Sauvegarder"}
        </button>
        {save.kind === "saved" ? (
          <span className="inline-flex items-center gap-1.5 text-sm text-success">
            <CheckCircle aria-hidden className="size-4" /> Sauvegardé
          </span>
        ) : null}
        {save.kind === "error" ? (
          <span className="inline-flex items-center gap-1.5 text-sm text-[color:var(--color-danger)]">
            <AlertCircle aria-hidden className="size-4" /> {save.message}
          </span>
        ) : null}
      </div>
    </div>
  );
}

const inputClass =
  "min-h-10 w-full rounded border border-border bg-background px-3 text-sm text-foreground outline-none focus:border-accent";

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid gap-1.5" role="group" aria-label={label}>
      <span className="text-sm font-medium text-foreground">{label}</span>
      {children}
    </div>
  );
}

function updateLink(
  setSocialLinks: React.Dispatch<React.SetStateAction<EditableSocialLink[]>>,
  index: number,
  patch: Partial<EditableSocialLink>,
): void {
  setSocialLinks((prev) => prev.map((link, i) => (i === index ? { ...link, ...patch } : link)));
}
