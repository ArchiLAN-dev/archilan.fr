"use client";

import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Compass, Loader2, PlugZap, Shovel } from "lucide-react";

import { Switch } from "@/components/switch";

import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { wholeNumber } from "./admin-blind-test-card";
import { checkMinimapperzConnection, fetchMinimapperzSettings, saveMinimapperzSettings, type MinimapperzCheck, type MinimapperzSettings } from "./minimapperz-api";

const dateFormatter = new Intl.DateTimeFormat("fr-FR", { dateStyle: "medium", timeStyle: "short", timeZone: "Europe/Paris" });

/** The outcome of a connection check, in words (null = the site itself could not ask). */
export function minimapperzCheckMessage(check: MinimapperzCheck | null): { tone: "ok" | "error"; text: string } {
  if (check === null) return { tone: "error", text: "Test impossible. Réessaie." };
  switch (check.status) {
    case "ok":
      return {
        tone: "ok",
        text: `Connecté à « ${check.event.name} », du ${dateFormatter.format(new Date(check.event.opensAt))} au ${dateFormatter.format(new Date(check.event.closesAt))}, ${check.event.isOpen ? "ouvert" : "fermé"} en ce moment.`,
      };
    case "not_configured":
      return { tone: "error", text: "Enregistre d'abord le slug et la clé." };
    case "unauthorized":
      return { tone: "error", text: "Minimapperz refuse la clé : vérifie la clé et le slug de l'event." };
    case "unreachable":
      return { tone: "error", text: "Minimapperz ne répond pas. Réessaie dans un moment." };
  }
}

/**
 * Story 49.1: the event's link to Minimapperz, admin side. The slug (or the LAN's address), the organiser API key -
 * written here, never shown back - and what a game played and a game won are worth, up to a cap per member.
 */
export function AdminMinimapperzCard({ eventId }: { eventId: string }) {
  const { data } = useQuery({
    queryKey: ["admin-minimapperz", eventId],
    queryFn: () => fetchMinimapperzSettings(eventId),
    staleTime: DEFAULT_STALE_TIME,
  });

  if (!data) return null;

  return <AdminMinimapperzForm eventId={eventId} initial={data} />;
}

function AdminMinimapperzForm({ eventId, initial }: { eventId: string; initial: MinimapperzSettings }) {
  const queryClient = useQueryClient();
  const [settings, setSettings] = useState(initial);
  const [enabled, setEnabled] = useState(initial.enabled);
  const [slug, setSlug] = useState(initial.slug ?? "");
  const [apiKey, setApiKey] = useState("");
  const [perGame, setPerGame] = useState(String(initial.pellesPerGame));
  const [perWin, setPerWin] = useState(String(initial.pellesPerWin));
  const [cap, setCap] = useState(String(initial.capPerPlayer));
  const [pending, setPending] = useState(false);
  const [checking, setChecking] = useState(false);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [check, setCheck] = useState<{ tone: "ok" | "error"; text: string } | null>(null);

  const pellesPerGame = wholeNumber(perGame);
  const pellesPerWin = wholeNumber(perWin);
  const capPerPlayer = wholeNumber(cap);
  const canSubmit = !pending && pellesPerGame !== null && pellesPerWin !== null && capPerPlayer !== null;

  async function save(clearApiKey: boolean) {
    if (pellesPerGame === null || pellesPerWin === null || capPerPlayer === null) return;
    setPending(true);
    const result = await saveMinimapperzSettings(eventId, {
      enabled: clearApiKey ? false : enabled,
      slug,
      apiKey: clearApiKey ? "" : apiKey,
      clearApiKey,
      pellesPerGame,
      pellesPerWin,
      capPerPlayer,
    });
    setPending(false);
    if (result.kind === "ok") {
      queryClient.setQueryData(["admin-minimapperz", eventId], result.settings);
      setSettings(result.settings);
      setEnabled(result.settings.enabled);
      setSlug(result.settings.slug ?? "");
      setApiKey("");
      setFields({});
      setCheck(null);
      setMessage({ tone: "ok", text: clearApiKey ? "Clé effacée, Minimapperz coupé." : "Réglages enregistrés." });
    } else {
      setFields(result.fields);
      setMessage({ tone: "error", text: result.message });
    }
  }

  async function runCheck() {
    setChecking(true);
    const result = await checkMinimapperzConnection(eventId);
    setChecking(false);
    setCheck(minimapperzCheckMessage(result));
  }

  const fieldClass = "min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground";
  const buttonClass =
    "inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-40";

  return (
    <form
      className="grid gap-4"
      onSubmit={(event) => {
        event.preventDefault();
        void save(false);
      }}
    >
      <p className="text-sm text-muted-foreground">
        Relie l&apos;événement à sa LAN Minimapperz. Le site lit les parties terminées et crédite des pelles d&apos;événement
        aux inscrits qui ont joué avec leur code.
      </p>

      <fieldset className="grid gap-3 rounded-xl border border-border p-4">
        <legend className="flex items-center gap-2 px-1 font-heading text-base font-semibold text-foreground">
          <Compass aria-hidden className="size-4 text-accent-text" />
          Connexion
        </legend>
        <SwitchRow checked={enabled} hint="Coupé, aucune partie n'est lue et rien n'est crédité." label="Minimapperz activé" onChange={setEnabled} />
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Slug de l&apos;event ou adresse de la LAN</span>
          <input
            className={fieldClass}
            onChange={(e) => setSlug(e.target.value)}
            placeholder="https://app.minimapperz.com/lan/archilan-4/"
            type="text"
            value={slug}
          />
          {fields.slug ? <span className="text-xs text-danger">{fields.slug}</span> : null}
        </label>
        <label className="grid gap-1 text-sm">
          <span className="font-medium text-foreground">Clé API organisateur</span>
          <input
            autoComplete="off"
            className={fieldClass}
            onChange={(e) => setApiKey(e.target.value)}
            placeholder={settings.apiKeySet ? "Laisser vide pour garder la clé enregistrée" : "mmz_ev_..."}
            type="password"
            value={apiKey}
          />
          <span className="text-xs text-muted-foreground">
            {settings.apiKeySet ? `Clé enregistrée, se termine par ...${settings.apiKeyEnd ?? ""}.` : "Aucune clé enregistrée."} À créer sur la page admin
            de l&apos;event Minimapperz.
          </span>
          {fields.apiKey ? <span className="text-xs text-danger">{fields.apiKey}</span> : null}
        </label>
        <div className="flex flex-wrap items-center gap-3">
          <button className={buttonClass} disabled={checking || !settings.apiKeySet || settings.slug === null} onClick={() => void runCheck()} type="button">
            {checking ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <PlugZap aria-hidden className="size-4" />}
            Tester la connexion
          </button>
          {settings.apiKeySet ? (
            <button className={buttonClass} disabled={pending} onClick={() => void save(true)} type="button">
              Effacer la clé
            </button>
          ) : null}
        </div>
        {check !== null ? <p className={`text-sm ${check.tone === "ok" ? "text-success" : "text-danger"}`}>{check.text}</p> : null}
      </fieldset>

      <fieldset className="grid gap-3 rounded-xl border border-border p-4 sm:grid-cols-3">
        <legend className="flex items-center gap-2 px-1 font-heading text-base font-semibold text-foreground">
          <Shovel aria-hidden className="size-4 text-accent-text" />
          Gains
        </legend>
        <NumberField error={fields.pellesPerGame} label="Pelles par partie jouée (0 à 100)" max={100} min={0} onChange={setPerGame} value={perGame} />
        <NumberField error={fields.pellesPerWin} label="En plus pour une victoire (0 à 100)" max={100} min={0} onChange={setPerWin} value={perWin} />
        <NumberField error={fields.capPerPlayer} label="Plafond par membre (1 à 10 000)" max={10000} min={1} onChange={setCap} value={cap} />
      </fieldset>

      <div className="flex flex-wrap items-center gap-3">
        <button className={buttonClass} disabled={!canSubmit} type="submit">
          {pending ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
          Enregistrer
        </button>
        {message !== null ? <p className={`text-sm ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p> : null}
      </div>
    </form>
  );
}

function NumberField({
  label,
  value,
  min,
  max,
  error,
  onChange,
}: {
  label: string;
  value: string;
  min: number;
  max: number;
  error: string | undefined;
  onChange: (value: string) => void;
}) {
  return (
    <label className="grid content-start gap-1 text-sm">
      <span className="font-medium text-foreground">{label}</span>
      <input
        className="min-h-9 rounded-lg border border-border bg-background px-3 text-sm text-foreground"
        inputMode="numeric"
        max={max}
        min={min}
        onChange={(e) => onChange(e.target.value)}
        type="number"
        value={value}
      />
      {error ? <span className="text-xs text-danger">{error}</span> : null}
    </label>
  );
}

/** A setting turned on or off: its name, what it changes, and the switch at the end of the line. */
function SwitchRow({ label, hint, checked, onChange }: { label: string; hint: string; checked: boolean; onChange: (checked: boolean) => void }) {
  return (
    <div className="flex items-center justify-between gap-4">
      <div className="grid gap-0.5">
        <span className="text-sm font-medium text-foreground">{label}</span>
        <span className="text-xs text-muted-foreground">{hint}</span>
      </div>
      <Switch ariaLabel={label} checked={checked} onChange={onChange} />
    </div>
  );
}
