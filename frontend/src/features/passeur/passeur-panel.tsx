"use client";

import { useRef, useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { KeyRound, Loader2 } from "lucide-react";

import { ConfirmDialog, ConfirmFigure } from "@/components/ui/confirm-dialog";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { PelleAmount } from "@/features/wallet/pelle-amount";
import { callThePasseur, fetchPasseur, passeurKey, type PasseurCallResult, type PasseurOffer } from "./passeur-api";

const TIME = new Intl.DateTimeFormat("fr-FR", { hour: "2-digit", minute: "2-digit", timeZone: "Europe/Paris" });

/**
 * « Le Passeur » (story 52.4): for a player stuck in BK, the chest of another world that unblocks them, at the
 * price of the moment. Hidden when the party has no Passeur or nothing to offer.
 */
export function PasseurPanel({ sessionId, slotIndex }: { sessionId: string; slotIndex: string }) {
  const queryClient = useQueryClient();
  const { data } = useQuery({
    queryKey: passeurKey(sessionId, slotIndex),
    queryFn: () => fetchPasseur(sessionId, slotIndex),
    staleTime: DEFAULT_STALE_TIME,
    refetchInterval: 60_000,
    retry: false,
  });

  if (!data?.enabled || data.offer === null) return null;

  return (
    <PasseurView
      offer={data.offer}
      onCall={async (offer, requestId) => {
        const result = await callThePasseur(sessionId, slotIndex, offer, requestId);
        await queryClient.invalidateQueries({ queryKey: passeurKey(sessionId, slotIndex) });
        await queryClient.invalidateQueries({ queryKey: ["my-wallet"] });
        if (offer.kind === "bronze") await queryClient.invalidateQueries({ queryKey: ["run-bronze"] });
        return result;
      }}
    />
  );
}

export function PasseurView({ offer, onCall }: { offer: PasseurOffer; onCall: (offer: PasseurOffer, requestId: string) => Promise<PasseurCallResult> }) {
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState<{ tone: "ok" | "error"; text: string } | null>(null);
  // One id per call, kept across a retry so a double submit pays once.
  const requestIdRef = useRef<string | null>(null);

  const affordable = offer.balance >= offer.price;
  const waitingUntil = offer.availableAt ?? null;
  const unit = offer.kind === "bronze" ? "pelles de bronze" : "pelles d'argent";

  async function call(): Promise<void> {
    requestIdRef.current ??= crypto.randomUUID();
    setPending(true);
    setMessage(null);
    const result = await onCall(offer, requestIdRef.current);
    if (result.kind === "ok") {
      setMessage({ tone: "ok", text: `Le Passeur a ouvert le coffre de ${result.absentPlayerName} : ${result.itemName} arrive.` });
      requestIdRef.current = null;
    } else {
      setMessage({ tone: "error", text: result.message });
      // A refusal is final for this id: a new attempt is a new call.
      if (result.code !== "network_error") requestIdRef.current = null;
    }
    setPending(false);
    setConfirming(false);
  }

  return (
    <section aria-labelledby="passeur-title" className="mb-4 grid gap-3 rounded border border-violet-500/40 bg-surface p-4">
      <h2 className="flex items-center gap-2 font-heading text-sm font-semibold text-foreground" id="passeur-title">
        <KeyRound aria-hidden className="size-4 text-violet-400" />
        Le Passeur
      </h2>
      <p className="text-sm text-foreground">
        Tu es en BK depuis {TIME.format(new Date(offer.blockedSince))}. Le Passeur {waitingUntil === null ? "peut" : "pourra"} ouvrir le coffre <strong>{offer.locationName}</strong> chez{" "}
        <strong>{offer.absentPlayerName}</strong> et t&apos;envoyer <strong>{offer.itemName}</strong>.
      </p>
      <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
        <span className="flex items-center gap-1">
          Prix : <PelleAmount amount={offer.price} className="font-semibold" kind={offer.kind} />
          {offer.price < offer.basePrice ? <span>(au lieu de {offer.basePrice})</span> : null}
        </span>
        <span className="flex items-center gap-1">
          Ton solde : <PelleAmount amount={offer.balance} kind={offer.kind} />
        </span>
        {offer.nextDropAt !== null ? <span>Prochaine baisse à {TIME.format(new Date(offer.nextDropAt))}</span> : null}
      </div>
      <div>
        <button
          className="inline-flex min-h-8 items-center gap-1.5 rounded border border-violet-500/50 px-2.5 text-xs font-semibold text-foreground hover:border-violet-400 disabled:cursor-not-allowed disabled:opacity-40"
          disabled={pending || !affordable || waitingUntil !== null}
          onClick={() => setConfirming(true)}
          type="button"
        >
          {pending ? <Loader2 aria-hidden className="size-3 animate-spin" /> : <KeyRound aria-hidden className="size-3" />}
          Faire appel au Passeur
        </button>
        {waitingUntil !== null ? (
          <p className="mt-1 text-xs text-muted-foreground">Le Passeur sera disponible à {TIME.format(new Date(waitingUntil))}, si tu es toujours bloqué.</p>
        ) : !affordable ? (
          <p className="mt-1 text-xs text-muted-foreground">Il te manque des {unit}.</p>
        ) : null}
      </div>
      {message !== null ? <p className={`text-xs ${message.tone === "ok" ? "text-success" : "text-danger"}`}>{message.text}</p> : null}

      <ConfirmDialog
        confirmLabel="Faire appel au Passeur"
        description={`Le coffre est ouvert tout de suite chez ${offer.absentPlayerName}. Si le Passeur échoue, tes ${unit} te sont rendues.`}
        icon={KeyRound}
        onConfirm={() => void call()}
        onOpenChange={setConfirming}
        open={confirming}
        pending={pending}
        title="Faire appel au Passeur ?"
      >
        <ConfirmFigure label="Prix">
          <PelleAmount amount={offer.price} kind={offer.kind} />
        </ConfirmFigure>
      </ConfirmDialog>
    </section>
  );
}
