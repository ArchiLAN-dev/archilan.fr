"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { use, useRef, useState, type KeyboardEvent } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { ArrowLeft, Loader2, Music, Send, Shovel, Users, type LucideIcon } from "lucide-react";

import { ConfirmDialog, ConfirmFigure } from "@/components/ui/confirm-dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount, pellesLabel } from "./pelle-amount";
import { distributeEventPelles, fetchEventPelles, type EventPelles } from "./event-pelles-api";
import { AdminBlindTestCard } from "./admin-blind-test-card";
import { fetchBlindTestSettings } from "./blind-test-api";

const MAX_AMOUNT = 1000;
const dateFormatter = new Intl.DateTimeFormat("fr-FR", { dateStyle: "long", timeStyle: "short", timeZone: "Europe/Paris" });

/** What the admin confirms before handing pelles out (story 41.2 AC2, summarised in the modal since story 39.15). */
export type DistributionPreview = { amount: number; members: number; total: number; label: string };

export function distributionPreview(amount: number, members: number, label: string): DistributionPreview {
  return { amount, members, total: amount * members, label };
}

/** The figures of a distribution: per member, how many, in all - and the label the members will read. */
export function DistributionSummary({ preview }: { preview: DistributionPreview }) {
  return (
    <div className="grid gap-4">
      <div className="grid grid-cols-3 gap-3">
        <ConfirmFigure label="Par membre">
          <PelleAmount amount={preview.amount} />
        </ConfirmFigure>
        <ConfirmFigure label={preview.members > 1 ? "Membres" : "Membre"}>{preview.members}</ConfirmFigure>
        <ConfirmFigure label="Total">
          <PelleAmount amount={preview.total} className="text-success" />
        </ConfirmFigure>
      </div>
      <div className="grid gap-1 border-t border-border pt-3">
        <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Libellé, visible par les membres</span>
        <p className="text-sm text-foreground">{preview.label}</p>
      </div>
    </div>
  );
}

/** The outcome in words: who got pelles, and who did not. */
export function distributionSummary(result: { credited: number; skipped: number; alreadyCredited: number }): string {
  const parts = [`${result.credited} ${result.credited > 1 ? "membres crédités" : "membre crédité"}`];
  if (result.alreadyCredited > 0) {
    parts.push(`${result.alreadyCredited} ${result.alreadyCredited > 1 ? "déjà crédités" : "déjà crédité"} par cette distribution`);
  }
  if (result.skipped > 0) parts.push(`${result.skipped} ${result.skipped > 1 ? "sautés" : "sauté"} (compte banni ou supprimé)`);
  return `${parts.join(", ")}.`;
}

/** The tabs of the page (story 41.57); distributing and the blind test end with the event. */
export type EventPellesTab = "membres" | "distribuer" | "blind-test";

const TAB_PARAM = "onglet";

const TAB_META: Record<EventPellesTab, { label: string; icon: LucideIcon }> = {
  membres: { label: "Membres", icon: Users },
  distribuer: { label: "Distribuer", icon: Send },
  "blind-test": { label: "Blind test", icon: Music },
};

/** The tabs an event offers, in order: once it is over, only its members are left to look at. */
export function eventPellesTabs(ended: boolean): EventPellesTab[] {
  return ended ? ["membres"] : ["membres", "distribuer", "blind-test"];
}

/** The tab to open: the one in the address when the event offers it, else « Membres ». */
export function resolveEventPellesTab(param: string | null, ended: boolean): EventPellesTab {
  return eventPellesTabs(ended).find((tab) => tab === param) ?? "membres";
}

/**
 * The pelles of one event (story 41.2): who holds what, and the distribution to every active registrant or to
 * a selection. Once the event is over, its pelles have expired and only the outcome is shown. Story 41.57: in tabs,
 * the open one kept in the address.
 */
export function AdminEventPellesPage({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params);
  const searchParams = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const { data, isLoading } = useQuery({
    queryKey: ["admin-event-pelles", eventId],
    queryFn: () => fetchEventPelles(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  return (
    <div className="grid gap-6 p-6 md:p-8">
      <Link className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground" href="/admin/evenements">
        <ArrowLeft aria-hidden className="size-4" />
        Événements
      </Link>
      {isLoading ? <p className="text-sm text-muted-foreground">Chargement…</p> : null}
      {!isLoading && !data ? <p className="text-sm text-danger">Impossible de charger les pelles de cet événement.</p> : null}
      {data ? (
        <EventPellesView
          data={data}
          onTab={(tab) => router.replace(`${pathname}?${TAB_PARAM}=${tab}`, { scroll: false })}
          tab={resolveEventPellesTab(searchParams.get(TAB_PARAM), data.ended)}
        />
      ) : null}
    </div>
  );
}

export function EventPellesView({ data, tab = "membres", onTab }: { data: EventPelles; tab?: EventPellesTab; onTab?: (tab: EventPellesTab) => void }) {
  // Without an address to keep it in (a test, a preview), the tab lives here.
  const [localTab, setLocalTab] = useState<EventPellesTab>(tab);
  const active = resolveEventPellesTab(onTab ? tab : localTab, data.ended);
  const select = onTab ?? setLocalTab;
  const { data: blindTest } = useQuery({
    queryKey: ["admin-blind-test", data.eventId],
    queryFn: () => fetchBlindTestSettings(data.eventId),
    staleTime: DEFAULT_STALE_TIME,
    enabled: !data.ended,
  });

  return (
    <div className="grid gap-6">
      <header className="grid gap-1">
        <h1 className="flex items-center gap-2 font-heading text-2xl font-bold text-foreground">
          <Shovel aria-hidden className="size-6 text-accent-text" />
          Pelles · {data.eventTitle}
        </h1>
        <p className="text-sm text-muted-foreground">
          {data.ended
            ? `Terminé le ${dateFormatter.format(new Date(data.endsAt))} : 10 % des pelles restantes sont passées en or, le reste a été détruit.`
            : `Les pelles de cet événement servent jusqu'au ${dateFormatter.format(new Date(data.endsAt))} ; ensuite 10 % passent en or et le reste disparaît.`}
        </p>
      </header>

      <dl className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Figure label="Distribuées" value={data.distributed} />
        <Figure label="En circulation" value={data.inCirculation} />
        {data.ended ? <Figure label="Converties en or" value={data.converted} /> : null}
        {data.ended ? <Figure label="Détruites" value={data.destroyed} /> : null}
      </dl>

      <EventPellesTabs
        active={active}
        badges={{ membres: String(data.participants.length), "blind-test": blindTest?.enabled === true ? "ouvert" : undefined }}
        onSelect={select}
        tabs={eventPellesTabs(data.ended)}
      />

      <div aria-labelledby={`pelles-tab-${active}`} className="grid gap-4" id={`pelles-panel-${active}`} role="tabpanel">
        {active === "membres" ? <MembersPanel data={data} /> : null}
        {active === "distribuer" ? <DistributePanel data={data} /> : null}
        {active === "blind-test" ? <AdminBlindTestCard eventId={data.eventId} /> : null}
      </div>
    </div>
  );
}

/** The tab bar, as on the member sheet (story 36.8): scrolls sideways on a phone, the arrow keys move between tabs. */
function EventPellesTabs({
  tabs,
  active,
  badges,
  onSelect,
}: {
  tabs: EventPellesTab[];
  active: EventPellesTab;
  badges: Partial<Record<EventPellesTab, string | undefined>>;
  onSelect: (tab: EventPellesTab) => void;
}) {
  function onKeyDown(event: KeyboardEvent<HTMLDivElement>): void {
    if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") return;
    event.preventDefault();
    const index = tabs.indexOf(active);
    const next = tabs[(index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) % tabs.length];
    onSelect(next);
    document.getElementById(`pelles-tab-${next}`)?.focus();
  }

  return (
    <div
      aria-label="Sections des pelles"
      className="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1 [scrollbar-width:none]"
      onKeyDown={onKeyDown}
      role="tablist"
    >
      {tabs.map((id) => {
        const { label, icon: Icon } = TAB_META[id];
        const badge = badges[id];
        return (
          <button
            aria-controls={`pelles-panel-${id}`}
            aria-selected={id === active}
            className={`-mb-px inline-flex min-h-11 shrink-0 items-center gap-2 border-b-2 px-4 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 ${
              id === active ? "border-accent text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"
            }`}
            id={`pelles-tab-${id}`}
            key={id}
            onClick={() => onSelect(id)}
            role="tab"
            tabIndex={id === active ? 0 : -1}
            type="button"
          >
            <Icon aria-hidden className="size-4 shrink-0" />
            {label}
            {badge !== undefined ? (
              <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${id === "blind-test" ? "bg-success/15 text-success" : "bg-surface-2 text-muted-foreground"}`}>
                {badge}
              </span>
            ) : null}
          </button>
        );
      })}
    </div>
  );
}

type Participant = EventPelles["participants"][number];

function MemberTags({ participant }: { participant: Participant }) {
  return (
    <>
      {participant.banned ? <span className="rounded-full bg-danger/15 px-2 py-0.5 text-xs text-danger">banni</span> : null}
      {participant.registered ? null : <span className="rounded-full bg-surface-2 px-2 py-0.5 text-xs text-muted-foreground">non inscrit</span>}
    </>
  );
}

function MembersPanel({ data }: { data: EventPelles }) {
  if (data.participants.length === 0) {
    return <p className="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">Aucun inscrit actif, aucune pelle gagnée.</p>;
  }
  return (
    <ul aria-label={`Membres (${data.participants.length})`} className="divide-y divide-border rounded-xl border border-border">
      {data.participants.map((participant) => (
        <li className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm" key={participant.userId}>
          <span className="flex min-w-0 items-center gap-2.5">
            <span className="truncate text-foreground">{participant.displayName}</span>
            <MemberTags participant={participant} />
          </span>
          <PelleAmount amount={participant.balance} className="shrink-0 font-semibold" />
        </li>
      ))}
    </ul>
  );
}

const RECIPIENT_MODES = [
  { value: "all", label: (eligible: number) => `Tous les inscrits (${eligible})` },
  { value: "selection", label: () => "Une sélection" },
] as const;

function DistributePanel({ data }: { data: EventPelles }) {
  const queryClient = useQueryClient();
  const [amount, setAmount] = useState("");
  const [label, setLabel] = useState("");
  const [mode, setMode] = useState<"all" | "selection">("all");
  const [selected, setSelected] = useState<Set<string>>(() => new Set());
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);
  const [confirming, setConfirming] = useState<DistributionPreview | null>(null);
  // One id per distribution, kept across a retry so a double submit credits nobody twice.
  const requestIdRef = useRef<string | null>(null);

  const eligible = data.participants.filter((p) => p.registered && !p.banned);
  const recipients = mode === "all" ? eligible.length : selected.size;
  const parsedAmount = Number.parseInt(amount, 10);
  const amountValid = Number.isInteger(parsedAmount) && parsedAmount >= 1 && parsedAmount <= MAX_AMOUNT;
  const canSubmit = !pending && amountValid && label.trim() !== "" && recipients > 0;

  function toggle(userId: string): void {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(userId)) next.delete(userId);
      else next.add(userId);
      return next;
    });
  }

  function submit(): void {
    if (!canSubmit) return;
    setConfirming(distributionPreview(parsedAmount, recipients, label.trim()));
  }

  async function distribute(): Promise<void> {
    requestIdRef.current ??= crypto.randomUUID();

    setPending(true);
    setMessage(null);
    const result = await distributeEventPelles(data.eventId, {
      amount: parsedAmount,
      label,
      requestId: requestIdRef.current,
      userIds: mode === "all" ? null : [...selected],
    });
    if (result.kind === "ok") {
      requestIdRef.current = null;
      setMessage({ tone: "ok", text: distributionSummary(result) });
      setAmount("");
      setLabel("");
      setSelected(new Set());
      await queryClient.invalidateQueries({ queryKey: ["admin-event-pelles", data.eventId] });
    } else {
      setMessage({ tone: "error", text: result.message });
    }
    setPending(false);
    setConfirming(null);
  }

  const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";
  const smallButton =
    "inline-flex min-h-8 items-center rounded-lg border border-border px-2.5 text-xs font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40";

  return (
    <form
      className="grid gap-4 rounded-xl border border-border p-4 sm:grid-cols-2"
      onSubmit={(event) => {
        event.preventDefault();
        submit();
      }}
    >
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Pelles par membre (1 à {MAX_AMOUNT})</span>
        <input className={fieldClass} inputMode="numeric" max={MAX_AMOUNT} min={1} onChange={(e) => setAmount(e.target.value)} type="number" value={amount} />
      </label>
      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Libellé (visible par les membres)</span>
        <input className={fieldClass} maxLength={200} onChange={(e) => setLabel(e.target.value)} placeholder="Happening du samedi" type="text" value={label} />
      </label>

      <div className="grid gap-2 sm:col-span-2">
        <span className="text-sm font-medium text-foreground" id="pelles-recipients">
          Destinataires
        </span>
        <div aria-labelledby="pelles-recipients" className="inline-flex w-fit flex-wrap rounded-lg border border-border p-0.5" role="radiogroup">
          {RECIPIENT_MODES.map((option) => (
            <button
              aria-checked={mode === option.value}
              className={`min-h-8 rounded-md px-3 text-sm font-semibold transition-colors ${mode === option.value ? "bg-accent text-white" : "text-muted-foreground hover:text-foreground"}`}
              key={option.value}
              onClick={() => setMode(option.value)}
              role="radio"
              type="button"
            >
              {option.label(eligible.length)}
            </button>
          ))}
        </div>
        {mode === "selection" ? (
          <div className="grid gap-2">
            <div className="flex flex-wrap items-center gap-2 text-sm">
              <span className="text-muted-foreground">
                {selected.size} / {eligible.length} {eligible.length > 1 ? "inscrits cochés" : "inscrit coché"}
              </span>
              <button className={smallButton} disabled={selected.size === eligible.length} onClick={() => setSelected(new Set(eligible.map((p) => p.userId)))} type="button">
                Tout cocher
              </button>
              <button className={smallButton} disabled={selected.size === 0} onClick={() => setSelected(new Set())} type="button">
                Tout décocher
              </button>
            </div>
            <ul className="max-h-80 divide-y divide-border overflow-y-auto rounded-xl border border-border">
              {eligible.map((participant) => (
                <li key={participant.userId}>
                  <label className="flex cursor-pointer items-center justify-between gap-3 px-4 py-2.5 text-sm">
                    <span className="flex min-w-0 items-center gap-2.5">
                      <input checked={selected.has(participant.userId)} onChange={() => toggle(participant.userId)} type="checkbox" />
                      <span className="truncate text-foreground">{participant.displayName}</span>
                    </span>
                    <PelleAmount amount={participant.balance} className="shrink-0 text-muted-foreground" />
                  </label>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>

      <div className="sm:col-span-2">
        <button
          className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40"
          disabled={!canSubmit}
          type="submit"
        >
          {pending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <Shovel aria-hidden className="size-4" />}
          Distribuer à {recipients} {recipients > 1 ? "membres" : "membre"}
        </button>
      </div>
      {message !== null ? <p className={`text-sm sm:col-span-2 ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p> : null}

      <ConfirmDialog
        confirmLabel={`Distribuer ${pellesLabel(confirming?.total ?? 0)}`}
        description={`Chaque membre reçoit ses pelles de ${data.eventTitle} tout de suite, avec ce libellé dans son historique.`}
        icon={Shovel}
        onConfirm={() => void distribute()}
        onOpenChange={(open) => {
          if (!open && !pending) setConfirming(null);
        }}
        open={confirming !== null}
        pending={pending}
        title="Distribuer des pelles ?"
      >
        {confirming !== null ? <DistributionSummary preview={confirming} /> : null}
      </ConfirmDialog>
    </form>
  );
}

function Figure({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-xl border border-border p-4">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className="mt-1 font-heading text-2xl font-bold">
        <PelleAmount amount={value} />
      </dd>
    </div>
  );
}
