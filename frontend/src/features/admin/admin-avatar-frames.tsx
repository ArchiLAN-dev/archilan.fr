"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Upload } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { hasBooleanProp, hasNumberProp, hasStringProp } from "@/lib/type-guards";
import { AVATAR_FRAME_CATALOG_QUERY_KEY, type AvatarFrameAccess } from "@/features/community/avatar-frame-catalog";
import { getAvatarFrame } from "@/features/community/avatar-frames";

/** Story 41.10: a video frame as the admin manages it. */
export type AdminAvatarFrame = {
  key: string;
  label: string;
  access: AvatarFrameAccess;
  builtIn: boolean;
  retired: boolean;
  position: number;
  video: { webm: string; mp4: string; poster: string; still: string } | null;
};

export const ACCESS_LABELS: Record<AvatarFrameAccess, string> = {
  free: "Tout le monde",
  members: "Adhérents",
  admins: "Admins",
  shop: "Boutique",
  reward: "Récompense",
};

const ACCESSES = Object.keys(ACCESS_LABELS) as AvatarFrameAccess[];
const FILES = [
  { role: "webm", label: "Vidéo WebM (VP9, 3 Mo max)", accept: "video/webm" },
  { role: "mp4", label: "Vidéo MP4 (H.264, 3 Mo max)", accept: "video/mp4" },
  { role: "poster", label: "Aperçu WebP 512 x 512 (sur fond noir)", accept: "image/webp" },
  { role: "still", label: "Image fixe WebP 512 x 512 (transparente)", accept: "image/webp" },
] as const;

function isAdminFrame(v: unknown): v is AdminAvatarFrame {
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
    "video" in v
  );
}

export async function fetchAdminAvatarFrames(): Promise<AdminAvatarFrame[] | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/avatar-frames`);
    if (!res.ok) return null;
    const payload: unknown = await res.json();
    return typeof payload === "object" && payload !== null && "frames" in payload && Array.isArray(payload.frames) && payload.frames.every(isAdminFrame)
      ? payload.frames
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
export async function uploadAvatarFrame(fields: { key: string; label: string; access: AvatarFrameAccess }, files: Record<string, File>): Promise<string | null> {
  try {
    const body = new FormData();
    body.append("key", fields.key);
    body.append("label", fields.label);
    body.append("access", fields.access);
    for (const [role, file] of Object.entries(files)) body.append(role, file);
    const res = await apiFetch(`${env.apiBaseUrl}/admin/avatar-frames`, { method: "POST", body });
    return res.status === 201 ? null : await errorOf(res, "Le téléversement a échoué.");
  } catch {
    return "Impossible de contacter l'API.";
  }
}

export async function updateAvatarFrame(key: string, change: { label?: string; access?: AvatarFrameAccess; position?: number }): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/avatar-frames/${key}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(change),
    });
    return res.status === 204 ? null : await errorOf(res, "La modification a échoué.");
  } catch {
    return "Impossible de contacter l'API.";
  }
}

export async function setAvatarFrameRetired(key: string, retired: boolean): Promise<string | null> {
  try {
    const res = await apiFetch(`${env.apiBaseUrl}/admin/avatar-frames/${key}/${retired ? "retire" : "restore"}`, { method: "POST" });
    return res.status === 204 ? null : await errorOf(res, "L'opération a échoué.");
  } catch {
    return "Impossible de contacter l'API.";
  }
}

/** The poster of a frame: its own for an uploaded one, the code's for a built-in one. */
export function framePoster(frame: AdminAvatarFrame): string | null {
  return frame.video?.poster ?? getAvatarFrame(frame.key)?.video?.poster ?? null;
}

/**
 * « Cadres » (story 41.10): the video frames, their access and order, and a new frame from its four prepared files.
 * A frame for the shop is then put on sale in « Boutique ».
 */
export function AdminAvatarFramesPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ["admin-avatar-frames"], queryFn: fetchAdminAvatarFrames, staleTime: DEFAULT_STALE_TIME, retry: false });
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);

  async function after(error: string | null, ok: string): Promise<void> {
    setMessage(error === null ? { tone: "ok", text: ok } : { tone: "error", text: error });
    await queryClient.invalidateQueries({ queryKey: ["admin-avatar-frames"] });
    await queryClient.invalidateQueries({ queryKey: AVATAR_FRAME_CATALOG_QUERY_KEY });
  }

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header>
        <h1 className="font-heading text-2xl font-bold text-foreground">Cadres</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Les cadres vidéo d&apos;avatar : à qui chacun est ouvert, et l&apos;ajout d&apos;un nouveau cadre. Un cadre « Boutique » se
          met ensuite en vente dans la page Boutique.
        </p>
      </header>

      <UploadForm onDone={(error) => after(error, "Cadre ajouté.")} />

      {message !== null ? <p className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p> : null}

      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && !data ? <p className="text-sm text-danger">Impossible de charger les cadres.</p> : null}
      {data ? (
        <AdminAvatarFrameList
          frames={data}
          onAccess={async (key, access) => after(await updateAvatarFrame(key, { access }), "Accès modifié.")}
          onRetire={async (key, retired) => after(await setAvatarFrameRetired(key, retired), retired ? "Cadre retiré." : "Cadre rétabli.")}
        />
      ) : null}
    </section>
  );
}

export function AdminAvatarFrameList({
  frames,
  onAccess,
  onRetire,
}: {
  frames: AdminAvatarFrame[];
  onAccess: (key: string, access: AvatarFrameAccess) => Promise<void>;
  onRetire: (key: string, retired: boolean) => Promise<void>;
}) {
  return (
    <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {frames.map((frame) => {
        const poster = framePoster(frame);
        return (
          <li className={`grid gap-3 rounded-xl border border-border p-4 ${frame.retired ? "opacity-60" : ""}`} key={frame.key}>
            <div className="flex items-center gap-3">
              {poster !== null ? (
                // eslint-disable-next-line @next/next/no-img-element -- a remote poster from the media bucket, no optimisation needed
                <img alt="" className="size-16 shrink-0 rounded-lg bg-black object-cover" src={poster} />
              ) : (
                <span aria-hidden className="size-16 shrink-0 rounded-lg bg-black" />
              )}
              <div className="min-w-0">
                <p className="font-semibold text-foreground">{frame.label}</p>
                <p className="text-xs text-muted-foreground">
                  {frame.key}
                  {frame.builtIn ? " · intégré" : ""}
                  {frame.retired ? " · retiré" : ""}
                </p>
              </div>
            </div>
            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
              <label className="flex items-center gap-2">
                <span className="text-muted-foreground">Accès</span>
                <select
                  className="min-h-8 rounded border border-border bg-background px-2 text-sm"
                  onChange={(e) => void onAccess(frame.key, e.target.value as AvatarFrameAccess)}
                  value={frame.access}
                >
                  {ACCESSES.map((access) => (
                    <option key={access} value={access}>
                      {ACCESS_LABELS[access]}
                    </option>
                  ))}
                </select>
              </label>
              <button className="text-xs text-muted-foreground hover:text-foreground" onClick={() => void onRetire(frame.key, !frame.retired)} type="button">
                {frame.retired ? "Rétablir" : "Retirer"}
              </button>
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
  const complete = key.trim() !== "" && label.trim() !== "" && FILES.every((f) => files[f.role] !== undefined);

  async function submit(): Promise<void> {
    setPending(true);
    const error = await uploadAvatarFrame({ key: key.trim(), label: label.trim(), access }, files);
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
      <h2 className="font-heading text-lg font-semibold text-foreground sm:col-span-3">Ajouter un cadre</h2>
      <p className="text-xs text-muted-foreground sm:col-span-3">
        Les fichiers arrivent préparés : une vidéo de lumière sur fond noir, bouclée, à la géométrie commune des cadres (512 px,
        ouverture 107 à 403 sur 111 à 407), en WebM et MP4, avec son aperçu sur fond noir et son image fixe transparente. Rien
        n&apos;est converti ici. Pas de visuel généré par IA : un dessin de membre.
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
