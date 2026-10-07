"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import type { AvatarFrameAccess } from "@/features/community/avatar-frame-catalog";
import { ProfileTitleBadge } from "@/features/community/profile-title-badge";
import {
  ADMIN_PROFILE_TITLES_QUERY_KEY,
  PROFILE_TITLE_CATALOG_QUERY_KEY,
  PROFILE_TITLE_LIMITS,
  TITLE_ACCESS_LABELS,
  TITLE_ICONS,
  TITLE_ICON_LABELS,
  TITLE_RARITIES,
  TITLE_RARITY_LABELS,
  badgeOf,
  fetchAdminProfileTitles,
  setProfileTitleRetired,
  titleKeyFrom,
  updateProfileTitle,
  writeProfileTitle,
  type AdminProfileTitle,
  type TitleIcon,
  type TitleRarity,
} from "@/features/community/profile-title-catalog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";

const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";
const ACCESSES: readonly AvatarFrameAccess[] = ["free", "members", "admins", "shop", "reward"];

type Message = { tone: "ok" | "error"; text: string };

/**
 * `/admin/titres` (story 41.22): the profile titles members wear under their name - written here, open to everyone,
 * members, admins, or sold in the shop (then put on sale from the shop page).
 */
export function AdminProfileTitlesPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ADMIN_PROFILE_TITLES_QUERY_KEY, queryFn: fetchAdminProfileTitles, staleTime: DEFAULT_STALE_TIME, retry: false });

  async function after(error: string | null): Promise<string | null> {
    await queryClient.invalidateQueries({ queryKey: ADMIN_PROFILE_TITLES_QUERY_KEY });
    await queryClient.invalidateQueries({ queryKey: PROFILE_TITLE_CATALOG_QUERY_KEY });
    await queryClient.invalidateQueries({ queryKey: ["admin-shop"] });
    return error;
  }

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header>
        <h1 className="font-heading text-2xl font-bold text-foreground">Titres</h1>
        <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
          Les titres que les membres portent sous leur pseudo. Un titre en accès « Boutique » se met ensuite en vente depuis la{" "}
          <Link className="text-accent-text hover:underline" href="/admin/boutique">
            Boutique
          </Link>
          .
        </p>
      </header>
      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && !data ? <p className="text-sm text-danger">Impossible de charger les titres.</p> : null}
      {data ? <AdminProfileTitlesView onChange={after} titles={data} /> : null}
    </section>
  );
}

export function AdminProfileTitlesView({ titles, onChange }: { titles: AdminProfileTitle[]; onChange: (error: string | null) => Promise<string | null> }) {
  const [message, setMessage] = useState<Message | null>(null);
  const [editing, setEditing] = useState<AdminProfileTitle | "new" | null>(null);
  const [pending, setPending] = useState(false);

  async function apply(run: () => Promise<string | null>, ok: string): Promise<boolean> {
    setPending(true);
    const error = await onChange(await run());
    setPending(false);
    setMessage(error === null ? { tone: "ok", text: ok } : { tone: "error", text: error });
    return error === null;
  }

  return (
    <div className="grid gap-3">
      {message ? (
        <p className={`rounded-lg border px-3 py-2 text-sm ${message.tone === "ok" ? "border-success/40 text-success" : "border-danger/40 text-danger"}`} role="status">
          {message.text}
        </p>
      ) : null}
      <div className="flex justify-end">
        <button className={buttonVariants({ variant: "primary" })} onClick={() => setEditing("new")} type="button">
          <Plus aria-hidden className="size-4" />
          Nouveau titre
        </button>
      </div>
      {titles.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">Aucun titre pour l&apos;instant.</p>
      ) : (
        <ul className="divide-y divide-border overflow-hidden rounded-lg border border-border bg-surface" role="list">
          {titles.map((title) => (
            <li className={`flex flex-wrap items-center gap-3 px-4 py-3 ${title.retired ? "opacity-60" : ""}`} key={title.key}>
              <ProfileTitleBadge title={badgeOf(title)} />
              <span className="text-xs text-muted-foreground">{title.key}</span>
              <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">{TITLE_RARITY_LABELS[title.rarity]}</span>
              <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">{TITLE_ACCESS_LABELS[title.access]}</span>
              {title.retired ? <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">Retiré</span> : null}
              <div className="ml-auto flex gap-1">
                <button className={buttonVariants({ variant: "ghost" })} onClick={() => setEditing(title)} type="button">
                  Modifier
                </button>
                <button
                  className={buttonVariants({ variant: "ghost" })}
                  disabled={pending}
                  onClick={() => void apply(() => setProfileTitleRetired(title.key, !title.retired), title.retired ? "Titre rétabli." : "Titre retiré.")}
                  type="button"
                >
                  {title.retired ? "Rétablir" : "Retirer"}
                </button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {editing !== null ? (
        <TitleDialog
          onClose={() => setEditing(null)}
          onSave={async (label, access, rarity, icon) => {
            const done = await apply(
              () =>
                editing === "new"
                  ? writeProfileTitle({ key: titleKeyFrom(label), label, access, rarity, icon })
                  : updateProfileTitle(editing.key, { label, access, rarity, icon: icon ?? "" }),
              editing === "new" ? "Titre créé." : "Titre modifié.",
            );
            if (done) setEditing(null);
          }}
          pending={pending}
          title={editing === "new" ? null : editing}
        />
      ) : null}
    </div>
  );
}

function TitleDialog({
  title,
  pending,
  onSave,
  onClose,
}: {
  title: AdminProfileTitle | null;
  pending: boolean;
  onSave: (label: string, access: AvatarFrameAccess, rarity: TitleRarity, icon: TitleIcon | null) => Promise<void>;
  onClose: () => void;
}) {
  const [label, setLabel] = useState(title?.label ?? "");
  const [access, setAccess] = useState<AvatarFrameAccess>(title?.access ?? "shop");
  // Story 41.27: how the title shines, and its icon.
  const [rarity, setRarity] = useState<TitleRarity>(title?.rarity ?? "common");
  const [icon, setIcon] = useState<TitleIcon | null>(title?.icon ?? null);
  const trimmed = label.trim();
  const valid = trimmed.length >= PROFILE_TITLE_LIMITS.minLabel && trimmed.length <= PROFILE_TITLE_LIMITS.maxLabel && (title !== null || titleKeyFrom(trimmed).length >= 2);

  return (
    <Dialog
      description="Le texte porté sous le pseudo, et qui peut le choisir."
      onOpenChange={(open) => {
        if (!open) onClose();
      }}
      open
      title={title ? `Modifier « ${title.label} »` : "Nouveau titre"}
    >
      <form
        className="flex min-h-0 flex-1 flex-col"
        onSubmit={(event) => {
          event.preventDefault();
          if (valid) void onSave(trimmed, access, rarity, icon);
        }}
      >
        <DialogBody className="grid gap-3">
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Titre</span>
            <input className={fieldClass} maxLength={PROFILE_TITLE_LIMITS.maxLabel} onChange={(e) => setLabel(e.target.value)} placeholder="Chasseur de goals" value={label} />
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium text-foreground">Qui peut le porter</span>
            <select className={fieldClass} onChange={(e) => setAccess(ACCESSES.find((candidate) => candidate === e.target.value) ?? "shop")} value={access}>
              {ACCESSES.map((candidate) => (
                <option key={candidate} value={candidate}>
                  {TITLE_ACCESS_LABELS[candidate]}
                </option>
              ))}
            </select>
          </label>
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="grid gap-1 text-sm">
              <span className="font-medium text-foreground">Rareté</span>
              <select className={fieldClass} onChange={(e) => setRarity(TITLE_RARITIES.find((candidate) => candidate === e.target.value) ?? "common")} value={rarity}>
                {TITLE_RARITIES.map((candidate) => (
                  <option key={candidate} value={candidate}>
                    {TITLE_RARITY_LABELS[candidate]}
                  </option>
                ))}
              </select>
            </label>
            <label className="grid gap-1 text-sm">
              <span className="font-medium text-foreground">Icône</span>
              <select className={fieldClass} onChange={(e) => setIcon(TITLE_ICONS.find((candidate) => candidate === e.target.value) ?? null)} value={icon ?? ""}>
                <option value="">Aucune</option>
                {TITLE_ICONS.map((candidate) => (
                  <option key={candidate} value={candidate}>
                    {TITLE_ICON_LABELS[candidate]}
                  </option>
                ))}
              </select>
            </label>
          </div>
          {trimmed !== "" ? (
            <div className="grid gap-2 text-sm">
              <span className="font-medium text-foreground">Aperçu</span>
              <div className="flex flex-wrap items-center gap-4 rounded-lg border border-border bg-background p-4">
                <ProfileTitleBadge title={{ label: trimmed, rarity, icon, access }} />
                <ProfileTitleBadge title={{ label: trimmed, rarity, icon, access }} variant="card" />
              </div>
            </div>
          ) : null}
        </DialogBody>
        <DialogFooter>
          <button className={buttonVariants({ variant: "ghost" })} onClick={onClose} type="button">
            Annuler
          </button>
          <button className={buttonVariants({ variant: "primary" })} disabled={!valid || pending} type="submit">
            {title ? "Enregistrer" : "Créer le titre"}
          </button>
        </DialogFooter>
      </form>
    </Dialog>
  );
}
