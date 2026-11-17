"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowDownLeft, ArrowUpRight, BarChart3, Check, Copy, Link2, Pencil, Plus } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { AdminLinkStats, VISITS_NOTE } from "@/features/admin/admin-link-stats";
import { Dialog, DialogBody, DialogFooter } from "@/components/ui/dialog";
import { fetchAdminLinks, saveLink, type TrackedLink } from "@/features/links/links-api";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { cn } from "@/lib/utils";

/**
 * Story 55.1: the tracked links `/l/{code}`. A path makes an inbound link (Discord, socials, posters lead to the
 * site), an https address an outbound one (the site sends a visitor elsewhere). Story 55.2: their counts, the most
 * visited over 30 days first, and the detail of one link.
 */
export const ADMIN_LINKS_KEY = ["admin-links"] as const;

const fieldClass = "min-h-9 w-full rounded-lg border border-border bg-background px-3 text-sm text-foreground";

/** Same rule as the API (`TrackedLink::CODE_PATTERN`). */
export const LINK_CODE_PATTERN = "[a-z0-9][a-z0-9\\-]{1,38}[a-z0-9]";

export function linkUrl(code: string): string {
  return `${env.appUrl}/l/${code}`;
}

export type DirectionFilter = "all" | TrackedLink["direction"];

const FILTERS: { value: DirectionFilter; label: string }[] = [
  { value: "all", label: "Tous" },
  { value: "inbound", label: "Entrants" },
  { value: "outbound", label: "Sortants" },
];

/** The links of the chosen direction, the most visited over 30 days first, then over all time, then the newest. */
export function shownLinks(links: readonly TrackedLink[], filter: DirectionFilter): TrackedLink[] {
  return links
    .filter((link) => filter === "all" || link.direction === filter)
    .sort((a, b) => b.visits.last30 - a.visits.last30 || b.visits.total - a.visits.total || b.createdAt.localeCompare(a.createdAt));
}

export function AdminLinks() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ADMIN_LINKS_KEY, queryFn: fetchAdminLinks, staleTime: DEFAULT_STALE_TIME, retry: false });
  const [editing, setEditing] = useState<TrackedLink | "new" | null>(null);
  const [viewing, setViewing] = useState<TrackedLink | null>(null);
  const [filter, setFilter] = useState<DirectionFilter>("all");

  async function refresh(): Promise<void> {
    await queryClient.invalidateQueries({ queryKey: ADMIN_LINKS_KEY });
  }

  return (
    <section className="grid gap-6 p-6 md:p-8">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-heading text-2xl font-bold text-foreground">Liens suivis</h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
            Un lien court <code>/l/code</code> compte chaque passage puis redirige. Vers une page du site, il mesure ce qu&apos;un canal nous amène
            (Discord, réseaux, affiches) ; vers un autre site, ce qu&apos;on lui envoie. Aucune donnée personnelle n&apos;est gardée.
          </p>
        </div>
        <button className={buttonVariants({ variant: "primary" })} onClick={() => setEditing("new")} type="button">
          <Plus aria-hidden className="size-4" />
          Nouveau lien
        </button>
      </header>

      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && data === null ? <p className="text-sm text-danger">Impossible de charger les liens.</p> : null}
      {data?.length === 0 ? (
        <p className="flex items-center gap-2 rounded-xl border border-dashed border-border p-6 text-sm text-muted-foreground">
          <Link2 aria-hidden className="size-5" />
          Aucun lien pour l&apos;instant.
        </p>
      ) : null}
      {data !== undefined && data !== null && data.length > 0 ? (
        <>
          <div aria-label="Sens des liens" className="flex flex-wrap gap-2" role="group">
            {FILTERS.map((option) => (
              <button
                aria-pressed={filter === option.value}
                className={buttonVariants({ variant: filter === option.value ? "primary" : "secondary" })}
                key={option.value}
                onClick={() => setFilter(option.value)}
                type="button"
              >
                {option.label}
              </button>
            ))}
          </div>
          <ul className="grid gap-3">
            {shownLinks(data, filter).map((link) => (
              <LinkRow key={link.id} link={link} onEdit={() => setEditing(link)} onStats={() => setViewing(link)} />
            ))}
          </ul>
          <p className="text-xs text-muted-foreground">{VISITS_NOTE}</p>
        </>
      ) : null}

      <Dialog
        onOpenChange={(open) => {
          if (!open) setEditing(null);
        }}
        open={editing !== null}
        size="wide"
        title={editing === "new" ? "Nouveau lien" : "Modifier le lien"}
        variant="side"
      >
        {editing !== null ? (
          <LinkForm
            current={editing === "new" ? null : editing}
            onSaved={async () => {
              await refresh();
              setEditing(null);
            }}
          />
        ) : null}
      </Dialog>

      <Dialog
        onOpenChange={(open) => {
          if (!open) setViewing(null);
        }}
        open={viewing !== null}
        size="wide"
        title={viewing !== null ? `Statistiques : ${viewing.label}` : "Statistiques"}
        variant="side"
      >
        {viewing !== null ? (
          <DialogBody>
            <AdminLinkStats link={viewing} />
          </DialogBody>
        ) : null}
      </Dialog>
    </section>
  );
}

function LinkRow({ link, onEdit, onStats }: { link: TrackedLink; onEdit: () => void; onStats: () => void }) {
  const [copied, setCopied] = useState(false);
  const outbound = link.direction === "outbound";
  const Direction = outbound ? ArrowUpRight : ArrowDownLeft;

  async function copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(linkUrl(link.code));
      setCopied(true);
    } catch {
      setCopied(false);
    }
  }

  return (
    <li className="grid gap-3 rounded-xl border border-border bg-surface p-4 sm:grid-cols-[1fr_auto] sm:items-center">
      <div className="grid min-w-0 gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="font-heading text-base font-semibold text-foreground">{link.label}</h2>
          <span className="inline-flex items-center gap-1 rounded-full border border-border px-2 py-0.5 text-xs font-semibold text-muted-foreground">
            <Direction aria-hidden className="size-3" />
            {outbound ? "Sortant" : "Entrant"}
          </span>
          {link.partnerId !== null ? (
            <span className="rounded-full border border-border px-2 py-0.5 text-xs font-semibold text-muted-foreground">Partenaire</span>
          ) : null}
          {link.active ? null : <span className="rounded-full border border-border px-2 py-0.5 text-xs font-semibold text-muted-foreground">Désactivé</span>}
        </div>
        <p className="truncate font-mono text-sm text-foreground">{linkUrl(link.code)}</p>
        <p className="truncate text-xs text-muted-foreground">vers {link.target}</p>
        <p className="text-sm text-foreground">
          <span className="font-semibold tabular-nums">{link.visits.last30}</span> sur 30 jours
          <span className="text-muted-foreground">
            {" "}
            · {link.visits.last7} sur 7 jours · {link.visits.total} au total
          </span>
        </p>
      </div>
      <div className="flex flex-wrap gap-2">
        <button className={buttonVariants({ variant: "secondary" })} onClick={onStats} type="button">
          <BarChart3 aria-hidden className="size-4" />
          Statistiques
        </button>
        <button aria-label={`Copier le lien ${link.code}`} className={buttonVariants({ variant: "secondary" })} onClick={() => void copy()} type="button">
          {copied ? <Check aria-hidden className="size-4" /> : <Copy aria-hidden className="size-4" />}
          {copied ? "Copié" : "Copier"}
        </button>
        {/* Story 55.3: a partner's link follows its partner (address, name, shown or hidden): it is edited there. */}
        {link.partnerId === null ? (
          <button aria-label={`Modifier ${link.label}`} className={buttonVariants({ variant: "ghost" })} onClick={onEdit} type="button">
            <Pencil aria-hidden className="size-4" />
          </button>
        ) : null}
      </div>
    </li>
  );
}

type FormProps = { current: TrackedLink | null; onSaved: () => Promise<void> };

export function LinkForm({ current, onSaved }: FormProps) {
  const [code, setCode] = useState(current?.code ?? "");
  const [label, setLabel] = useState(current?.label ?? "");
  const [target, setTarget] = useState(current?.target ?? "");
  const [active, setActive] = useState(current?.active ?? true);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const valid = code.trim() !== "" && label.trim() !== "" && target.trim() !== "";

  async function submit(): Promise<void> {
    setPending(true);
    setError(null);
    const saved = await saveLink({ code: code.trim(), label, target: target.trim(), active }, current?.id ?? null);
    setPending(false);
    if (!saved.ok) {
      setError(saved.message);
      return;
    }
    await onSaved();
  }

  return (
    <form
      className="flex min-h-0 flex-1 flex-col"
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
    >
      <DialogBody>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Code</span>
          <input
            className={cn(fieldClass, "font-mono", current !== null && "opacity-70")}
            disabled={current !== null}
            maxLength={40}
            onChange={(e) => setCode(e.target.value.toLowerCase())}
            pattern={LINK_CODE_PATTERN}
            placeholder="discord-novembre"
            required
            type="text"
            value={code}
          />
          <span className="text-xs text-muted-foreground">
            {current !== null
              ? "Le code ne change plus : le lien est peut-être déjà diffusé ou imprimé."
              : `Minuscules, chiffres et tirets, 3 à 40 caractères. Le lien sera ${linkUrl(code.trim() === "" ? "code" : code.trim())}.`}
          </span>
        </label>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Libellé</span>
          <input className={fieldClass} maxLength={120} onChange={(e) => setLabel(e.target.value)} placeholder="Post Discord de novembre" required type="text" value={label} />
        </label>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Destination</span>
          <input className={fieldClass} maxLength={500} onChange={(e) => setTarget(e.target.value)} placeholder="/evenements/archilan-4" required type="text" value={target} />
          <span className="text-xs text-muted-foreground">Une page du site qui commence par /, ou une adresse en https:// pour un autre site.</span>
        </label>
        <label className="flex items-center gap-2 text-sm text-foreground">
          <input checked={active} className="size-4" onChange={(e) => setActive(e.target.checked)} type="checkbox" />
          Actif (désactivé, le lien mène à l&apos;accueil sans compter)
        </label>
        {error !== null ? (
          <p className="text-sm text-danger" role="alert">
            {error}
          </p>
        ) : null}
      </DialogBody>
      <DialogFooter>
        <button className={buttonVariants({ variant: "primary" })} disabled={!valid || pending} type="submit">
          {current === null ? "Créer le lien" : "Enregistrer"}
        </button>
      </DialogFooter>
    </form>
  );
}
