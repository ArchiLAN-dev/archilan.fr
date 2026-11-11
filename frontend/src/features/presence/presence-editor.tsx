"use client";

import { use, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { CalendarClock, Plus, Trash2 } from "lucide-react";

import { buttonVariants } from "@/components/ui/button";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  adminPresenceKey,
  describeWindow,
  fetchAdminPresence,
  fetchPresence,
  fromLocalInput,
  presenceKey,
  saveAdminPresence,
  savePresence,
  toLocalInput,
  type Presence,
  type PresenceWindow,
} from "./presence-api";

type Row = { key: string; start: string; end: string };

function rowsOf(windows: PresenceWindow[]): Row[] {
  return windows.map((window, index) => ({ key: `w${index}`, start: toLocalInput(window.startsAt), end: toLocalInput(window.endsAt) }));
}

/** The rows as windows to send, or the message of the first incomplete row. */
export function windowsOfRows(rows: { start: string; end: string }[]): { windows: PresenceWindow[] } | { error: string } {
  const windows: PresenceWindow[] = [];
  for (const row of rows) {
    const startsAt = fromLocalInput(row.start);
    const endsAt = fromLocalInput(row.end);
    if (startsAt === null || endsAt === null) return { error: "Indique une arrivée et un départ pour chaque plage." };
    windows.push({ startsAt, endsAt });
  }
  return { windows };
}

type EditorProps = {
  presence: Presence;
  title: string;
  intro: string;
  onSave: (windows: PresenceWindow[]) => Promise<string | null>;
};

/** Story 52.2: the windows of one registration, edited as a whole list. No window: there the whole event. */
export function PresenceEditor({ presence, title, intro, onSave }: EditorProps) {
  const [rows, setRows] = useState<Row[]>(() => rowsOf(presence.windows));
  const [counter, setCounter] = useState(presence.windows.length);
  const [state, setState] = useState<{ kind: "idle" | "saving" | "saved" } | { kind: "error"; message: string }>({ kind: "idle" });
  const min = toLocalInput(presence.eventStartsAt);
  const max = toLocalInput(presence.eventEndsAt);

  function update(key: string, patch: Partial<Row>) {
    setRows((current) => current.map((row) => (row.key === key ? { ...row, ...patch } : row)));
    setState({ kind: "idle" });
  }

  function add() {
    setRows((current) => [...current, { key: `n${counter}`, start: current.length === 0 ? min : "", end: current.length === 0 ? max : "" }]);
    setCounter((n) => n + 1);
    setState({ kind: "idle" });
  }

  async function save() {
    const parsed = windowsOfRows(rows);
    if ("error" in parsed) {
      setState({ kind: "error", message: parsed.error });
      return;
    }
    setState({ kind: "saving" });
    const failure = await onSave(parsed.windows);
    setState(failure === null ? { kind: "saved" } : { kind: "error", message: failure });
  }

  return (
    <section aria-labelledby="presence-heading" className="mt-8 grid gap-4 rounded-lg border border-border bg-surface p-5">
      <h2 className="flex items-center gap-2 font-heading text-lg font-bold text-foreground" id="presence-heading">
        <CalendarClock aria-hidden="true" className="size-5 text-accent-text" />
        {title}
      </h2>
      <p className="text-sm text-muted-foreground">{intro}</p>

      {!presence.editable ? (
        <PresenceSummary windows={presence.windows} />
      ) : (
        <>
          {rows.length === 0 ? <p className="text-sm text-foreground">Présent tout l&apos;événement.</p> : null}
          <ul className="grid gap-3">
            {rows.map((row, index) => (
              <li className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end" key={row.key}>
                <label className="grid gap-1 text-xs text-muted-foreground">
                  Arrivée {rows.length > 1 ? index + 1 : ""}
                  <input
                    className="min-h-10 rounded border border-border bg-background px-3 text-sm text-foreground"
                    max={max}
                    min={min}
                    onChange={(event) => update(row.key, { start: event.target.value })}
                    type="datetime-local"
                    value={row.start}
                  />
                </label>
                <label className="grid gap-1 text-xs text-muted-foreground">
                  Départ {rows.length > 1 ? index + 1 : ""}
                  <input
                    className="min-h-10 rounded border border-border bg-background px-3 text-sm text-foreground"
                    max={max}
                    min={min}
                    onChange={(event) => update(row.key, { end: event.target.value })}
                    type="datetime-local"
                    value={row.end}
                  />
                </label>
                <button
                  aria-label="Retirer cette plage"
                  className={buttonVariants({ variant: "ghost" })}
                  onClick={() => {
                    setRows((current) => current.filter((r) => r.key !== row.key));
                    setState({ kind: "idle" });
                  }}
                  type="button"
                >
                  <Trash2 aria-hidden="true" className="size-4" />
                </button>
              </li>
            ))}
          </ul>
          <div className="flex flex-wrap items-center gap-3">
            <button className={buttonVariants({ variant: "secondary" })} onClick={add} type="button">
              <Plus aria-hidden="true" className="size-4" />
              {rows.length === 0 ? "Je ne suis pas là tout le temps" : "Ajouter une plage"}
            </button>
            <button className={buttonVariants({ variant: "primary" })} disabled={state.kind === "saving"} onClick={() => void save()} type="button">
              {state.kind === "saving" ? "Enregistrement…" : "Enregistrer"}
            </button>
            {state.kind === "saved" ? <span className="text-sm text-success">Enregistré.</span> : null}
          </div>
          {state.kind === "error" ? (
            <p className="text-sm text-danger" role="alert">
              {state.message}
            </p>
          ) : null}
        </>
      )}
    </section>
  );
}

export function PresenceSummary({ windows }: { windows: PresenceWindow[] }) {
  if (windows.length === 0) return <p className="text-sm text-foreground">Présent tout l&apos;événement.</p>;
  return (
    <ul className="grid gap-1 text-sm text-foreground">
      {windows.map((window) => (
        <li key={`${window.startsAt}-${window.endsAt}`}>{describeWindow(window)}</li>
      ))}
    </ul>
  );
}

/** Story 52.2: on the member's own registration (recap, session), when they are at the event. */
export function PresenceCard({ params }: { params: Promise<{ registrationId: string }> }) {
  const { registrationId } = use(params);
  return <RegistrationPresenceCard registrationId={registrationId} />;
}

/** Story 53.3: the same card for a known registration (the registrant hub has no route params to hand over). */
export function RegistrationPresenceCard({ registrationId }: { registrationId: string }) {
  const queryClient = useQueryClient();
  const { data } = useQuery({
    queryKey: presenceKey(registrationId),
    queryFn: () => fetchPresence(registrationId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  // Signed out, someone else's registration, cancelled seat or event over: nothing to say.
  if (!data?.editable) return null;
  return (
    <PresenceEditor
      intro="Tu arrives plus tard ou tu repars avant la fin ? Dis-le à l'orga. Sans plage, on te compte présent tout l'événement."
      key={data.windows.map((w) => w.startsAt).join("|")}
      onSave={async (windows) => {
        const failure = await savePresence(registrationId, windows);
        if (failure === null) void queryClient.invalidateQueries({ queryKey: presenceKey(registrationId) });
        return failure;
      }}
      presence={data}
      title="Quand es-tu là ?"
    />
  );
}

/** Story 52.2: on the admin registration page, the same windows, editable whatever the event's state. */
export function AdminPresencePanel({ eventId, registrationId }: { eventId: string; registrationId: string }) {
  const queryClient = useQueryClient();
  const { data } = useQuery({
    queryKey: adminPresenceKey(eventId, registrationId),
    queryFn: () => fetchAdminPresence(eventId, registrationId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (!data) return null;
  return (
    <PresenceEditor
      intro="Quand le joueur est là. Sans plage : présent tout l'événement."
      key={data.windows.map((w) => w.startsAt).join("|")}
      onSave={async (windows) => {
        const failure = await saveAdminPresence(eventId, registrationId, windows);
        if (failure === null) void queryClient.invalidateQueries({ queryKey: adminPresenceKey(eventId, registrationId) });
        return failure;
      }}
      presence={{ ...data, editable: true }}
      title="Présence"
    />
  );
}
