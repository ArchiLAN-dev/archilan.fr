"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { KeyRound, Loader2, Undo2, UserPlus } from "lucide-react";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  assignMinimapperzCodes,
  detachMinimapperzCode,
  fetchMinimapperzCodes,
  importMinimapperzCodes,
  type MinimapperzAssignment,
  type MinimapperzCodes,
  type MinimapperzImport,
} from "./minimapperz-api";

/** The outcome of an import, in words. */
export function importSummary(result: MinimapperzImport): string {
  const parts = [`${result.added} ${result.added > 1 ? "codes ajoutés" : "code ajouté"}`];
  if (result.duplicates > 0) parts.push(`${result.duplicates} déjà présent${result.duplicates > 1 ? "s" : ""}`);
  if (result.invalid.length > 0) parts.push(`${result.invalid.length} ligne${result.invalid.length > 1 ? "s" : ""} ignorée${result.invalid.length > 1 ? "s" : ""} (espace ou trop long) : ${result.invalid.join(", ")}`);
  return `${parts.join(", ")}.`;
}

/** The outcome of handing codes to every registrant, in words. */
export function assignmentSummary(result: MinimapperzAssignment): string {
  const done = result.assigned === 0 ? "Aucun code attribué" : `${result.assigned} ${result.assigned > 1 ? "codes attribués" : "code attribué"}`;
  return result.withoutCode > 0 ? `${done}. ${result.withoutCode} inscrit${result.withoutCode > 1 ? "s" : ""} sans code : il n'en reste plus de libre.` : `${done}.`;
}

/**
 * Story 49.2: the Minimapperz codes of the event. The admin pastes the list Minimapperz gave; each registrant gets
 * a code on opening the event's page, or all at once from here. A code can be taken back, and is then free again.
 */
export function AdminMinimapperzCodes({ eventId }: { eventId: string }) {
  const queryClient = useQueryClient();
  const queryKey = ["admin-minimapperz-codes", eventId];
  const { data } = useQuery({ queryKey, queryFn: () => fetchMinimapperzCodes(eventId), staleTime: DEFAULT_STALE_TIME });
  const [text, setText] = useState("");
  const [pending, setPending] = useState<string | null>(null);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);

  if (!data) return null;

  function show(overview: MinimapperzCodes) {
    queryClient.setQueryData(queryKey, overview);
  }

  async function runImport() {
    setPending("import");
    const result = await importMinimapperzCodes(eventId, text);
    setPending(null);
    if (result.kind === "ok") {
      show(result.result.overview);
      setText("");
      setMessage({ tone: "ok", text: importSummary(result.result) });
    } else {
      setMessage({ tone: "error", text: result.message });
    }
  }

  async function runAssign() {
    setPending("assign");
    const result = await assignMinimapperzCodes(eventId);
    setPending(null);
    if (result.kind === "ok") {
      show(result.result.overview);
      setMessage({ tone: result.result.withoutCode > 0 ? "error" : "ok", text: assignmentSummary(result.result) });
    } else {
      setMessage({ tone: "error", text: result.message });
    }
  }

  async function runDetach(code: string) {
    setPending(code);
    const result = await detachMinimapperzCode(eventId, code);
    setPending(null);
    if (result.kind === "ok") {
      show(result.result);
      setMessage({ tone: "ok", text: `Code ${code} repris, il est de nouveau libre.` });
    } else {
      setMessage({ tone: "error", text: result.message });
    }
  }

  const buttonClass =
    "inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40";

  return (
    <section aria-labelledby="minimapperz-codes-title" className="grid gap-4 rounded-xl border border-border p-4">
      <h2 className="flex items-center gap-2 font-heading text-base font-semibold text-foreground" id="minimapperz-codes-title">
        <KeyRound aria-hidden className="size-4 text-accent-text" />
        Codes des joueurs
      </h2>
      <p className="text-sm text-muted-foreground">
        Chaque inscrit reçoit un code en ouvrant la page de l&apos;événement, et le saisit en rejoignant la LAN sur Minimapperz : c&apos;est ce qui relie ses
        parties à son compte.
      </p>

      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Count label="Importés" value={data.imported} />
        <Count label="Attribués" value={data.assigned} />
        <Count label="Libres" value={data.free} />
        <Count label="Inscrits sans code" value={data.registrantsWithoutCode} />
      </dl>
      {data.registrantsWithoutCode > data.free ? (
        <p className="text-sm text-danger" role="status">
          Il manque {data.registrantsWithoutCode - data.free} code{data.registrantsWithoutCode - data.free > 1 ? "s" : ""} pour couvrir tous les inscrits.
        </p>
      ) : null}

      <label className="grid gap-1 text-sm">
        <span className="font-medium text-foreground">Coller les codes, un par ligne</span>
        <textarea
          className="min-h-28 rounded-lg border border-border bg-background px-3 py-2 font-mono text-sm text-foreground"
          onChange={(e) => setText(e.target.value)}
          spellCheck={false}
          value={text}
        />
      </label>
      <div className="flex flex-wrap items-center gap-3">
        <button className={buttonClass} disabled={pending !== null || text.trim() === ""} onClick={() => void runImport()} type="button">
          {pending === "import" ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          Importer
        </button>
        <button className={buttonClass} disabled={pending !== null || data.registrantsWithoutCode === 0 || data.free === 0} onClick={() => void runAssign()} type="button">
          {pending === "assign" ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <UserPlus aria-hidden className="size-4" />}
          Attribuer à tous les inscrits
        </button>
      </div>
      {message !== null ? <p className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p> : null}

      {data.codes.length > 0 ? (
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th className="py-2 pr-3 font-medium">Code</th>
                <th className="py-2 pr-3 font-medium">Membre</th>
                <th className="py-2 font-medium">
                  <span className="sr-only">Action</span>
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {data.codes.map((row) => (
                <tr key={row.code}>
                  <td className="py-2 pr-3 font-mono text-foreground">{row.code}</td>
                  <td className="py-2 pr-3">
                    {row.userId === null ? (
                      <span className="text-muted-foreground">Libre</span>
                    ) : (
                      <span className="text-foreground">
                        {row.displayName ?? "Compte supprimé"}
                        {row.registered ? null : <span className="ml-2 text-xs text-muted-foreground">(inscription annulée)</span>}
                      </span>
                    )}
                  </td>
                  <td className="py-2 text-right">
                    {row.userId !== null ? (
                      <button
                        className="inline-flex min-h-8 items-center gap-1 rounded-lg border border-border px-2 text-xs font-semibold text-foreground transition-colors hover:border-accent disabled:opacity-40"
                        disabled={pending !== null}
                        onClick={() => void runDetach(row.code)}
                        type="button"
                      >
                        <Undo2 aria-hidden className="size-3.5" />
                        Reprendre
                      </button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </section>
  );
}

function Count({ label, value }: { label: string; value: number }) {
  return (
    <div className="grid gap-0.5 rounded-lg border border-border px-3 py-2">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="font-heading text-lg font-semibold text-foreground">{value}</dd>
    </div>
  );
}
