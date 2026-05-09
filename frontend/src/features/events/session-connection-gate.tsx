"use client";

import { use, useCallback, useEffect, useState } from "react";
import { AlertCircle, Check, Clock, Copy, Download, XCircle } from "lucide-react";

import { apiFetch } from "@/lib/apiFetch";
import { env } from "@/lib/env";
import { useSSE } from "@/hooks/use-sse";
import { EventFeed } from "./event-feed";
import { PlayerProgressGrid } from "@/components/session/PlayerProgressGrid";
import { SessionPipelineBar } from "@/components/session/SessionPipeline";

// ─── Types ───────────────────────────────────────────────────────────────────

type SessionPayload = {
  id: string;
  status: string;
  host: string | null;
  port: number | null;
  password: string | null;
};

type SlotInfo = {
  slotName: string;
  slotOrder: number;
  gameId: string;
  gameName: string;
};

type ConnectionData = {
  session: SessionPayload | null;
  slots: SlotInfo[];
};

type GateState =
  | { kind: "loading" }
  | { kind: "data"; data: ConnectionData }
  | { kind: "not_found" }
  | { kind: "error"; message: string };

// ─── Constants ───────────────────────────────────────────────────────────────

const PRE_LAUNCH_STATUSES = ["draft", "validating", "ready", "generating", "generated", "launching"];

// ─── Main gate ───────────────────────────────────────────────────────────────

export function SessionConnectionGate({
  params,
}: {
  params: Promise<{ eventSlug: string; registrationId: string }>;
}) {
  const { eventSlug, registrationId } = use(params);
  const [gateState, setGateState] = useState<GateState>({ kind: "loading" });

  const fetchConnection = useCallback(async () => {
    const res = await apiFetch(
      `${env.apiBaseUrl}/registrations/${registrationId}/session-connection`,
    );

    if (res.status === 404) {
      setGateState({ kind: "not_found" });
      return;
    }

    if (!res.ok) {
      setGateState({ kind: "error", message: "Impossible de charger les informations de connexion." });
      return;
    }

    const payload: unknown = await res.json();
    const data = parseConnectionData(payload);

    if (!data) {
      setGateState({ kind: "error", message: "Réponse API invalide." });
      return;
    }

    setGateState((prev) => {
      if (prev.kind === "data" && JSON.stringify(prev.data) === JSON.stringify(data)) {
        return prev;
      }
      return { kind: "data", data };
    });
  }, [registrationId]);

  useEffect(() => {
    let cancelled = false;

    async function run() {
      const profileRes = await apiFetch(`${env.apiBaseUrl}/account/profile`);

      if (cancelled) return;

      if (profileRes.status === 401 || profileRes.status === 403) {
        window.location.href = `/connexion?returnTo=/evenements/${eventSlug}/inscription/${registrationId}/session`;
        return;
      }

      await fetchConnection();
    }

    void run().catch(() => {
      if (!cancelled) {
        setGateState({ kind: "error", message: "Impossible de contacter l'API." });
      }
    });

    return () => {
      cancelled = true;
    };
  }, [registrationId, eventSlug, fetchConnection]);

  if (gateState.kind === "loading") {
    return (
      <div className="grid gap-4 rounded-lg border border-border bg-surface p-6">
        <span className="sr-only">Chargement des informations de connexion…</span>
        <div aria-hidden="true" className="grid gap-4">
          <div className="h-6 w-48 animate-pulse rounded bg-surface-2" />
          {[0, 1, 2].map((i) => (
            <div key={i} className="grid gap-1.5">
              <div className="h-3 w-16 animate-pulse rounded bg-surface-2" />
              <div className="h-10 w-full animate-pulse rounded bg-surface-2" />
            </div>
          ))}
        </div>
      </div>
    );
  }

  if (gateState.kind === "not_found") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <XCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Inscription introuvable</p>
        <p className="text-sm text-muted-foreground">
          Cette inscription n&apos;existe pas ou n&apos;est plus accessible.
        </p>
      </div>
    );
  }

  if (gateState.kind === "error") {
    return (
      <div className="grid gap-4 card-glow rounded-lg border border-border p-8 text-center">
        <AlertCircle aria-hidden="true" className="mx-auto size-8 text-danger" />
        <p className="font-heading text-xl font-semibold text-foreground">Erreur</p>
        <p className="text-sm text-muted-foreground">{gateState.message}</p>
      </div>
    );
  }

  const { data } = gateState;

  return (
    <ConnectionView
      data={data}
      onRefetch={fetchConnection}
      registrationId={registrationId}
      onSessionUpdate={(session) => {
        setGateState({ kind: "data", data: { ...data, session } });
      }}
    />
  );
}

// ─── Connection view with live SSE ───────────────────────────────────────────

function ConnectionView({
  data,
  registrationId,
  onSessionUpdate,
  onRefetch,
}: {
  data: ConnectionData;
  registrationId: string;
  onSessionUpdate: (session: SessionPayload) => void;
  onRefetch: () => Promise<void>;
}) {
  const sessionId = data.session?.id ?? null;
  const isLive = data.session !== null &&
    !["running", "stopped", "finished", "failed", "crashed"].includes(data.session.status);

  const topicUrl = sessionId ? `/sessions/${sessionId}` : "";
  const mercureUrl = isLive && env.mercurePublicUrl ? env.mercurePublicUrl : null;

  const onMessage = useCallback(
    (incoming: unknown) => {
      const session = parseSession(incoming);
      if (session) onSessionUpdate(session);
    },
    [onSessionUpdate],
  );

  const fallbackPoll = useCallback(async () => {
    const res = await apiFetch(
      `${env.apiBaseUrl}/registrations/${registrationId}/session-connection`,
    );
    if (res.ok) {
      const payload: unknown = await res.json();
      const d = parseConnectionData(payload);
      if (d?.session) onSessionUpdate(d.session);
    }
  }, [registrationId, onSessionUpdate]);

  useSSE<unknown>(
    topicUrl,
    isLive ? mercureUrl : null,
    onMessage,
    isLive ? fallbackPoll : undefined,
  );

  const session = data.session;
  const isPreLaunch = session !== null && PRE_LAUNCH_STATUSES.includes(session.status);

  return (
    <article className="grid gap-8">
      <header className="grid gap-2">
        <h1 className="font-heading text-3xl font-bold leading-tight text-foreground">
          Connexion à la session
        </h1>
      </header>

      {session ? <SessionPipelineBar status={session.status} /> : null}

      {isPreLaunch ? (
        <WaitingCard onRefetch={onRefetch} />
      ) : (
        <SessionStatusBanner session={session} />
      )}

      {session?.status === "running" && session.host ? (
        <RunningConnectionCard session={session} />
      ) : null}

      {session && ["running", "stopped", "finished"].includes(session.status) ? (
        <PatchFilesSection registrationId={registrationId} />
      ) : null}

      {data.slots.length > 0 ? (
        <section className="grid gap-4">
          <h2 className="font-heading text-xl font-semibold text-foreground">Tes créneaux</h2>
          <div className="grid gap-3">
            {data.slots.map((slot) => (
              <SlotCard key={slot.slotOrder} slot={slot} />
            ))}
          </div>
        </section>
      ) : null}

      {session ? <PlayerProgressGrid runId={session.id} /> : null}

      {session ? <EventFeed runId={session.id} /> : null}
    </article>
  );
}

// ─── WaitingCard ──────────────────────────────────────────────────────────────

function WaitingCard({ onRefetch }: { onRefetch: () => Promise<void> }) {
  const [countdown, setCountdown] = useState(30);

  useEffect(() => {
    const id = setInterval(() => {
      setCountdown((prev) => (prev > 0 ? prev - 1 : prev));
    }, 1000);
    return () => { clearInterval(id); };
  }, []);

  useEffect(() => {
    if (countdown !== 0) return;

    let cancelled = false;

    void onRefetch().finally(() => {
      if (!cancelled) {
        setCountdown(30);
      }
    });

    return () => {
      cancelled = true;
    };
  }, [countdown, onRefetch]);

  return (
    <div className="card-glow mx-auto grid w-full max-w-xl justify-items-center gap-4 rounded-lg border border-border bg-surface p-10 text-center">
      <Clock aria-hidden="true" className="size-10 animate-spin text-accent-warm" style={{ animationDuration: "3s" }} />
      <div>
        <p className="font-heading text-xl font-semibold text-foreground">La run démarre bientôt</p>
        <p className="mt-1 text-sm text-muted-foreground">Vérification dans {countdown}s…</p>
      </div>
    </div>
  );
}

// ─── RunningConnectionCard ────────────────────────────────────────────────────

function RunningConnectionCard({ session }: { session: SessionPayload }) {
  const [copiedField, setCopiedField] = useState<string | null>(null);
  const [copiedAll, setCopiedAll] = useState(false);

  function copyField(value: string, key: string) {
    void navigator.clipboard.writeText(value).then(() => {
      setCopiedField(key);
      setTimeout(() => { setCopiedField(null); }, 2000);
    });
  }

  function copyAll() {
    const text = `Adresse: ${session.host}:${session.port ?? ""} | Mot de passe: ${session.password ?? ""}`;
    void navigator.clipboard.writeText(text).then(() => {
      setCopiedAll(true);
      setTimeout(() => { setCopiedAll(false); }, 2000);
    });
  }

  return (
    <section className="card-glow grid gap-4 rounded-lg border border-success/40 bg-success/5 p-6">
      <div className="flex items-center gap-3">
        <span className="size-2.5 animate-pulse rounded-full bg-success" aria-hidden="true" />
        <h2 className="font-heading text-xl font-semibold text-foreground">
          Informations de connexion
        </h2>
        <span className="text-xs font-semibold uppercase tracking-wide text-success">EN LIGNE</span>
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <PlayerConnectionField
          ariaLabelCopy="Copier l'adresse"
          copied={copiedField === "host"}
          label="Adresse"
          onCopy={() => { copyField(session.host ?? "", "host"); }}
          value={session.host ?? ""}
        />
        <PlayerConnectionField
          ariaLabelCopy="Copier le port"
          copied={copiedField === "port"}
          label="Port"
          onCopy={() => { copyField(String(session.port ?? ""), "port"); }}
          value={String(session.port ?? "")}
        />
        <PlayerConnectionField
          ariaLabelCopy="Copier le mot de passe"
          copied={copiedField === "password"}
          label="Mot de passe"
          onCopy={() => { copyField(session.password ?? "", "password"); }}
          value={session.password ?? "-"}
        />
      </div>

      <button
        aria-label="Copier toutes les informations de connexion"
        className="inline-flex w-fit items-center gap-2 rounded border border-border px-3 py-1.5 text-sm text-muted-foreground hover:text-foreground"
        onClick={copyAll}
        type="button"
      >
        {copiedAll ? (
          <><Check aria-hidden="true" className="size-4 text-success" /> Copié !</>
        ) : (
          <><Copy aria-hidden="true" className="size-4" /> Tout copier</>
        )}
      </button>
    </section>
  );
}

function PlayerConnectionField({
  label,
  value,
  onCopy,
  copied,
  ariaLabelCopy,
}: {
  label: string;
  value: string;
  onCopy: () => void;
  copied: boolean;
  ariaLabelCopy: string;
}) {
  return (
    <div className="rounded border border-border bg-surface p-3">
      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{label}</p>
      <div className="mt-1 flex items-center justify-between gap-2">
        <span className="font-mono text-sm font-semibold text-foreground">{value}</span>
        <button
          aria-label={ariaLabelCopy}
          className="text-muted-foreground hover:text-foreground"
          onClick={onCopy}
          type="button"
        >
          {copied ? (
            <Check aria-hidden="true" className="size-4 text-success" />
          ) : (
            <Copy aria-hidden="true" className="size-4" />
          )}
        </button>
      </div>
    </div>
  );
}

// ─── Session status banner ────────────────────────────────────────────────────

function SessionStatusBanner({ session }: { session: SessionPayload | null }) {
  if (!session) {
    return (
      <div className="card-glow rounded-lg border border-border p-5">
        <p className="text-sm text-muted-foreground">
          Aucune session n&apos;a encore été créée pour cet événement.
        </p>
      </div>
    );
  }

  if (session.status === "stopped" || session.status === "finished") {
    return (
      <div className="rounded-lg border border-border bg-surface p-5">
        <p className="text-sm text-muted-foreground">La run est terminée.</p>
      </div>
    );
  }

  if (session.status === "failed" || session.status === "crashed") {
    return (
      <div className="rounded-lg border border-danger/40 bg-danger/5 p-5">
        <div className="flex items-center gap-3">
          <AlertCircle aria-hidden="true" className="size-4 text-danger" />
          <p className="font-semibold text-danger">La session a rencontré une erreur.</p>
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-lg border border-border bg-surface p-5">
      <p className="text-sm text-muted-foreground">
        La session est en cours de préparation.
      </p>
    </div>
  );
}

// ─── Connection field with copy button ───────────────────────────────────────

function ConnectionField({ label, value }: { label: string; value: string }) {
  const [copied, setCopied] = useState(false);

  function handleCopy() {
    void navigator.clipboard.writeText(value).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  }

  return (
    <div className="flex items-center justify-between gap-3 card-glow rounded-lg border border-border px-4 py-3">
      <div className="min-w-0">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className="truncate font-mono text-sm font-semibold text-foreground">{value}</p>
      </div>
      <button
        type="button"
        aria-label={`Copier ${label}`}
        className="shrink-0 rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        onClick={handleCopy}
      >
        {copied ? (
          <Check aria-hidden="true" className="size-4 text-success" />
        ) : (
          <Copy aria-hidden="true" className="size-4" />
        )}
      </button>
    </div>
  );
}

// ─── Slot card ────────────────────────────────────────────────────────────────

function SlotCard({ slot }: { slot: SlotInfo }) {
  return (
    <div className="flex items-center justify-between gap-3 card-glow rounded-lg border border-border px-4 py-3">
      <div className="min-w-0">
        <p className="font-semibold text-foreground">{slot.gameName}</p>
        <p className="text-xs text-muted-foreground">Slot : {slot.slotName}</p>
      </div>
      <ConnectionField label="Slot" value={slot.slotName} />
    </div>
  );
}

// ─── PatchFilesSection ────────────────────────────────────────────────────────

function PatchFilesSection({ registrationId }: { registrationId: string }) {
  const [files, setFiles] = useState<string[] | null>(null);

  useEffect(() => {
    void apiFetch(`${env.apiBaseUrl}/registrations/${registrationId}/patches`)
      .then((r) => r.ok ? r.json() as Promise<{ data: { files: string[] } }> : null)
      .then((j) => { setFiles(j?.data?.files ?? []); })
      .catch(() => { setFiles([]); });
  }, [registrationId]);

  if (files === null || files.length === 0) return null;

  return (
    <section className="grid gap-3">
      <h2 className="font-heading text-xl font-semibold text-foreground">Fichiers de patch</h2>
      <p className="text-sm text-muted-foreground">
        Ces fichiers sont nécessaires pour patcher votre ROM avant de rejoindre la session.
      </p>
      <div className="grid gap-2">
        {files.map((filename) => (
          <a
            className="flex items-center justify-between gap-3 rounded border border-border bg-surface px-4 py-3 hover:border-accent-text/40"
            download={filename}
            href={`${env.apiBaseUrl}/registrations/${registrationId}/patches/${encodeURIComponent(filename)}`}
            key={filename}
          >
            <span className="font-mono text-sm text-foreground">{filename}</span>
            <span className="flex shrink-0 items-center gap-1.5 text-xs font-semibold text-accent-text">
              <Download aria-hidden="true" className="size-3.5" />
              Télécharger
            </span>
          </a>
        ))}
      </div>
    </section>
  );
}

// ─── Parsers ─────────────────────────────────────────────────────────────────

function parseSession(x: unknown): SessionPayload | null {
  if (!x || typeof x !== "object") return null;
  const s = x as Record<string, unknown>;
  if (typeof s.id !== "string" || typeof s.status !== "string") return null;
  return {
    id: s.id,
    status: s.status,
    host: typeof s.host === "string" ? s.host : null,
    port: typeof s.port === "number" ? s.port : null,
    password: typeof s.password === "string" ? s.password : null,
  };
}

function parseSlot(x: unknown): SlotInfo | null {
  if (!x || typeof x !== "object") return null;
  const s = x as Record<string, unknown>;
  if (
    typeof s.slotName !== "string" ||
    typeof s.slotOrder !== "number" ||
    typeof s.gameId !== "string" ||
    typeof s.gameName !== "string"
  ) {
    return null;
  }
  return { slotName: s.slotName, slotOrder: s.slotOrder, gameId: s.gameId, gameName: s.gameName };
}

function parseConnectionData(payload: unknown): ConnectionData | null {
  if (!payload || typeof payload !== "object") return null;
  const root = (payload as { data?: unknown }).data;
  if (!root || typeof root !== "object") return null;
  const d = root as Record<string, unknown>;

  const session = d.session != null ? parseSession(d.session) : null;

  if (!Array.isArray(d.slots)) return null;

  const slots: SlotInfo[] = (d.slots as unknown[]).flatMap((s) => {
    const parsed = parseSlot(s);
    return parsed ? [parsed] : [];
  });

  return { session, slots };
}
