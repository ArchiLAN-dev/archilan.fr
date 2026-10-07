"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Upload } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import type { AvatarFrameAccess } from "@/features/community/avatar-frame-catalog";
import { ProfileBanner } from "@/features/community/profile-banner";
import { bannerVideo, isProfileBannerMedia, PROFILE_BANNER_CATALOG_QUERY_KEY, type ProfileBannerMedia } from "@/features/community/profile-banner-catalog";
import { ACCESS_LABELS } from "./admin-avatar-frames";

/** Story 41.11: a profile banner as the admin manages it. */
export type AdminProfileBanner = {
  key: string;
  label: string;
  access: AvatarFrameAccess;
  builtIn: boolean;
  retired: boolean;
  position: number;
  media: ProfileBannerMedia | null;
};

const ACCESSES = Object.keys(ACCESS_LABELS) as AvatarFrameAccess[];
const FILES = [
  { role: "image", label: "Image fixe WebP ou JPEG (1200 x 300 à 2400 x 800, 3 à 6 fois plus large que haute, 2 Mo max)", accept: "image/webp,image/jpeg" },
  { role: "webm", label: "Vidéo WebM (VP9, 6 Mo max) - bannière animée", accept: "video/webm" },
  { role: "mp4", label: "Vidéo MP4 (H.264, 6 Mo max) - bannière animée", accept: "video/mp4" },
] as const;

function isAdminBanner(v: unknown): v is AdminProfileBanner {
  return (
    typeof v === "object" &&
    v !== null &&
    hasStringProp(v, "key") &&
    hasStringProp(v, "label") &&
    hasStringProp(v, "access") &&
    ACCESSES.some((a) => a === v.access) &&
    hasBooleanProp(v, "builtIn") &&
    hasBooleanProp(v, "retired") &&
    hasNumberProp(v, "position") &&
    "media" in v &&
    (v.media === null || isProfileBannerMedia(v.media))
  );
}

export async function fetchAdminProfileBanners(): Promise<AdminProfileBanner[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/profile-banners`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return typeof payload === "object" && payload !== null && "banners" in payload && Array.isArray(payload.banners) && payload.banners.every(isAdminBanner)
      ? payload.banners
      : null;
  } catch {
    return null;
  }
}

async function errorOf(res: Response, fallback: string): Promise<string> {
  const payload: unknown = await res.json().catch(() => null);
  if (typeof payload === "object" && payload !== null && "error" in payload && typeof payload.error === "object" && payload.error !== null) {
    const error = payload.error;
    if ("details" in error && typeof error.details === "object" && error.details !== null) {
      const reasons = Object.values(error.details).flat().filter((r): r is string => typeof r === "string");
      if (reasons.length > 0) return reasons.join(" ");
    }
    if (hasStringProp(error, "message")) return error.message;
  }
  return fallback;
}

/** Null on success, otherwise the reason. */
export async function uploadProfileBanner(fields: { key: string; label: string; access: AvatarFrameAccess }, files: Record<string, File>): Promise<string | null> {
  try {
    const body = new FormData();
    body.append("key", fields.key);
    body.append("label", fields.label);
    body.append("access", fields.access);
    for (const [role, file] of Object.entries(files)) body.append(role, file);
    const res = await apiFetch(`${env.apiBaseUrl}/admin/profile-banners`, { method: "POST", body });
    return res.status === 201 ? null : await errorOf(res, "Le téléversement a échoué.");
  } catch {
    return "Impossible de contacter l'API.";
  }
}

export async function updateProfileBanner(key: string, change: { label?: string; access?: AvatarFrameAccess; position?: number }): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/profile-banners/${key}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(change),
    });
    return res.status === 204 ? null : await errorOf(res, "La modification a échoué.");
  } catch {
    return "Impossible de contacter l'API.";
  }
}

export async function setProfileBannerRetired(key: string, retired: boolean): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/profile-banners/${key}/${retired ? "retire" : "restore"}`, { method: "POST" });
    return res.status === 204 ? null : await errorOf(res, "L'opération a échoué.");
  } catch {
    return "Impossible de contacter l'API.";
  }
}

/**
 * « Bannières » (story 41.11): the profile banners, their access and order, and a new banner from its prepared
 * files - a still image, and a looped video for an animated one. A banner for the shop is then put on sale in
 * « Boutique ».
 */
export function AdminProfileBannersPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ["admin-profile-banners"], queryFn: fetchAdminProfileBanners, staleTime: DEFAULT_STALE_TIME, retry: false });
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);

  async function after(error: string | null, ok: string): Promise<void> {
    setMessage(error === null ? { tone: "ok", text: ok } : { tone: "error", text: error });
    await queryClient.invalidateQueries({ queryKey: ["admin-profile-banners"] });
    await queryClient.invalidateQueries({ queryKey: PROFILE_BANNER_CATALOG_QUERY_KEY });
  }

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header>
        <h1 className="font-heading text-2xl font-bold text-foreground">Bannières</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Les bannières de profil : à qui chacune est ouverte, leur ordre, et l&apos;ajout d&apos;une bannière fixe ou animée. Une
          bannière « Boutique » se met ensuite en vente dans la page Boutique.
        </p>
      </header>

      <UploadForm onDone={(error) => after(error, "Bannière ajoutée.")} />

      {message !== null ? <p className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p> : null}

      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && !data ? <p className="text-sm text-danger">Impossible de charger les bannières.</p> : null}
      {data ? (
        <AdminProfileBannerList
          banners={data}
          onAccess={async (key, access) => after(await updateProfileBanner(key, { access }), "Accès modifié.")}
          onRetire={async (key, retired) => after(await setProfileBannerRetired(key, retired), retired ? "Bannière retirée." : "Bannière rétablie.")}
        />
      ) : null}
    </section>
  );
}

export function AdminProfileBannerList({
  banners,
  onAccess,
  onRetire,
}: {
  banners: AdminProfileBanner[];
  onAccess: (key: string, access: AvatarFrameAccess) => Promise<void>;
  onRetire: (key: string, retired: boolean) => Promise<void>;
}) {
  return (
    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {banners.map((banner) => {
        // The default banner is what a profile falls back on: always open, never retired.
        const locked = banner.key === "default";
        const animated = banner.media !== null && bannerVideo(banner.media) !== null;
        return (
          <li className={`grid gap-3 rounded-xl border border-border p-4 ${banner.retired ? "opacity-60" : ""}`} key={banner.key}>
            <div className="overflow-hidden rounded-lg">
              <ProfileBanner className="h-16 w-full" compact media={banner.media} presetKey={banner.key} />
            </div>
            <div className="min-w-0">
              <p className="font-semibold text-foreground">{banner.label}</p>
              <p className="text-xs text-muted-foreground">
                {banner.key}
                {banner.builtIn ? " · intégrée" : animated ? " · animée" : " · fixe"}
                {banner.retired ? " · retirée" : ""}
              </p>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
              <label className="flex items-center gap-2">
                <span className="text-muted-foreground">Accès</span>
                <select
                  className="min-h-8 rounded border border-border bg-background px-2 text-sm"
                  disabled={locked}
                  onChange={(e) => void onAccess(banner.key, e.target.value as AvatarFrameAccess)}
                  value={banner.access}
                >
                  {ACCESSES.map((access) => (
                    <option key={access} value={access}>
                      {ACCESS_LABELS[access]}
                    </option>
                  ))}
                </select>
              </label>
              {locked ? null : (
                <button className="text-xs text-muted-foreground hover:text-foreground" onClick={() => void onRetire(banner.key, !banner.retired)} type="button">
                  {banner.retired ? "Rétablir" : "Retirer"}
                </button>
              )}
            </div>
          </li>
        );
      })}
    </ul>
  );
}

function UploadForm({ onDone }: { onDone: (error: string | null) => Promise<void> }) {
  const [key, setKey] = useState("");
  const [label, setLabel] = useState("");
  const [access, setAccess] = useState<AvatarFrameAccess>("shop");
  const [files, setFiles] = useState<Record<string, File>>({});
  const [pending, setPending] = useState(false);
  const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";
  const complete = key.trim() !== "" && label.trim() !== "" && files.image !== undefined;

  async function submit(): Promise<void> {
    setPending(true);
    const error = await uploadProfileBanner({ key: key.trim(), label: label.trim(), access }, files);
    if (error === null) {
      setKey("");
      setLabel("");
      setFiles({});
    }
    await onDone(error);
    setPending(false);
  }

  return (
    <form
      className="grid gap-3 rounded-xl border border-border p-4 sm:grid-cols-3"
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
    >
      <h2 className="font-heading text-lg font-semibold text-foreground sm:col-span-3">Ajouter une bannière</h2>
      <p className="text-xs text-muted-foreground sm:col-span-3">
        Une bannière fixe n&apos;a que son image. Une bannière animée ajoute une vidéo bouclée, sans son, en WebM et en MP4, de
        la taille de l&apos;image : l&apos;image sert d&apos;aperçu et reste affichée pour qui limite les animations. Les fichiers
        arrivent préparés, rien n&apos;est converti ici. La bannière est recadrée au centre selon l&apos;écran. Pas de visuel
        généré par IA : un dessin de membre.
      </p>
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Clé (minuscules, chiffres, _)</span>
        <input className={fieldClass} maxLength={32} onChange={(e) => setKey(e.target.value)} pattern="[a-z0-9_]{2,32}" value={key} />
      </label>
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Nom</span>
        <input className={fieldClass} maxLength={60} onChange={(e) => setLabel(e.target.value)} value={label} />
      </label>
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Accès</span>
        <select className={fieldClass} onChange={(e) => setAccess(e.target.value as AvatarFrameAccess)} value={access}>
          {ACCESSES.map((a) => (
            <option key={a} value={a}>
              {ACCESS_LABELS[a]}
            </option>
          ))}
        </select>
      </label>
      {FILES.map((f) => (
        <label className="grid gap-1 text-sm" key={f.role}>
          <span className="font-medium text-foreground">{f.label}</span>
          <input
            accept={f.accept}
            className="text-xs"
            onChange={(e) => {
              const file = e.target.files?.[0];
              setFiles((current) => {
                const next = { ...current };
                if (file === undefined) delete next[f.role];
                else next[f.role] = file;
                return next;
              });
            }}
            type="file"
          />
        </label>
      ))}
      <div className="sm:col-span-3">
        <button
          className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
          disabled={!complete || pending}
          type="submit"
        >
          {pending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Upload aria-hidden className="size-4" />}
          Ajouter
        </button>
      </div>
    </form>
  );
}
