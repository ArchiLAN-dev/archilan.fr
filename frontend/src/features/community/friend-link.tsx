"use client";

import { useState } from "react";
import Link from "next/link";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Copy, Loader2, Maximize2, QrCode, RefreshCw, UserPlus, X } from "lucide-react";
import { Dialog as RadixDialog } from "radix-ui";
import { create as createQrCode } from "qrcode";

import { buttonVariants } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { env } from "@/lib/env";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import {
  addFriendFromLink,
  fetchFriendLink,
  fetchMyFriendLinkCode,
  regenerateFriendLink,
  type FriendLinkView,
  type RelationshipState,
} from "./community-friends-api";
import { FriendIdentity } from "./friend-identity";

export const MY_FRIEND_LINK_KEY = ["community-friend-link"];

export function friendLinkUrl(code: string): string {
  return `${env.appUrl}/ami/${code}`;
}

/**
 * The dark modules of a QR code as one SVG path, a unit square each (story 43.3). Drawn here, without a network
 * call nor an image: the code never leaves the page.
 */
export function qrPath(value: string): { size: number; d: string } {
  const { size, data } = createQrCode(value, { errorCorrectionLevel: "M" }).modules;
  let d = "";
  for (let row = 0; row < size; row++) {
    for (let col = 0; col < size; col++) {
      if (data[row * size + col] === 1) d += `M${col} ${row}h1v1h-1z`;
    }
  }
  return { size, d };
}

const QUIET_ZONE = 2;

/** Black on white whatever the theme: a phone camera reads contrast, not tokens. */
function QrCodeSvg({ value, className }: { value: string; className?: string }) {
  const { size, d } = qrPath(value);
  const box = size + QUIET_ZONE * 2;
  return (
    <svg
      aria-label="QR code de mon lien d'ami"
      className={["rounded-lg bg-white", className].filter(Boolean).join(" ")}
      role="img"
      shapeRendering="crispEdges"
      viewBox={`${-QUIET_ZONE} ${-QUIET_ZONE} ${box} ${box}`}
    >
      <path className="fill-black" d={d} />
    </svg>
  );
}

/** « Mon lien d'ami » in /compte/amis: the link, its QR code, full screen to show it at a LAN, and a reset. */
export function MyFriendLink() {
  const queryClient = useQueryClient();
  const [copied, setCopied] = useState(false);
  const [fullScreen, setFullScreen] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [regenerating, setRegenerating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data: code, isLoading } = useQuery({
    queryKey: MY_FRIEND_LINK_KEY,
    queryFn: fetchMyFriendLinkCode,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (isLoading) return null;
  if (code === undefined || code === null) {
    return <p className="text-sm text-muted-foreground">Impossible de charger ton lien d&apos;ami.</p>;
  }

  const url = friendLinkUrl(code);

  async function copy() {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      /* clipboard unavailable */
    }
  }

  async function regenerate() {
    setRegenerating(true);
    setError(null);
    const next = await regenerateFriendLink();
    setRegenerating(false);
    setConfirming(false);
    if (next === null) {
      setError("Impossible de régénérer le lien.");
      return;
    }
    queryClient.setQueryData(MY_FRIEND_LINK_KEY, next);
  }

  return (
    <section className="grid gap-3">
      <h2 className="flex items-center gap-2 font-heading text-lg font-semibold text-foreground">
        <QrCode aria-hidden className="size-5 text-accent-text" />
        Mon lien d&apos;ami
      </h2>
      <div className="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:items-center">
        <button
          aria-label="Afficher le QR code en plein écran"
          className="shrink-0 self-center"
          onClick={() => setFullScreen(true)}
          type="button"
        >
          <QrCodeSvg className="size-36" value={url} />
        </button>
        <div className="grid min-w-0 flex-1 gap-3">
          <p className="text-sm text-muted-foreground">
            Fais scanner ce QR code à ton voisin de LAN, ou envoie-lui le lien : il t&apos;ajoute en ami sans chercher ton pseudo.
          </p>
          <div className="flex items-center gap-2">
            <span className="min-w-0 flex-1 truncate rounded border border-border bg-background px-3 py-2 font-mono text-sm text-muted-foreground">
              {url}
            </span>
            <button
              aria-label="Copier mon lien d'ami"
              className="shrink-0 rounded border border-border px-3 py-2 text-muted-foreground transition-colors hover:border-accent hover:text-foreground"
              onClick={() => void copy()}
              type="button"
            >
              {copied ? <Check aria-hidden className="size-4 text-success" /> : <Copy aria-hidden className="size-4" />}
            </button>
          </div>
          <div className="flex flex-wrap gap-2">
            <button className={buttonVariants({ variant: "secondary" })} onClick={() => setFullScreen(true)} type="button">
              <Maximize2 aria-hidden className="size-4" />
              Plein écran
            </button>
            <button className={buttonVariants({ variant: "ghost" })} onClick={() => setConfirming(true)} type="button">
              <RefreshCw aria-hidden className="size-4" />
              Régénérer
            </button>
          </div>
          {error !== null ? <p className="text-xs text-danger">{error}</p> : null}
        </div>
      </div>

      <RadixDialog.Root onOpenChange={setFullScreen} open={fullScreen}>
        <RadixDialog.Portal>
          <RadixDialog.Content
            aria-describedby={undefined}
            className="fixed inset-0 z-50 flex flex-col items-center justify-center gap-6 bg-white p-6 focus:outline-none"
          >
            <RadixDialog.Title className="font-heading text-xl font-bold text-black">Scanne pour m&apos;ajouter en ami</RadixDialog.Title>
            <QrCodeSvg className="aspect-square w-full max-w-[min(80vw,70vh)]" value={url} />
            <RadixDialog.Close className="inline-flex items-center gap-1.5 rounded-lg border border-black/20 px-4 py-2 text-sm font-semibold text-black">
              <X aria-hidden className="size-4" />
              Fermer
            </RadixDialog.Close>
          </RadixDialog.Content>
        </RadixDialog.Portal>
      </RadixDialog.Root>

      <ConfirmDialog
        confirmLabel="Régénérer"
        description="L'ancien lien et son QR code cesseront de fonctionner, y compris là où tu les as déjà partagés."
        icon={RefreshCw}
        onConfirm={() => void regenerate()}
        onOpenChange={setConfirming}
        open={confirming}
        pending={regenerating}
        title="Régénérer mon lien d'ami ?"
      />
    </section>
  );
}

/** What the visitor of a friend link can do, by relationship. Null: a button. */
export function friendLinkStatus(state: RelationshipState): string | null {
  switch (state) {
    case "self":
      return "C'est ton propre lien d'ami : montre-le à quelqu'un d'autre.";
    case "friends":
      return "Vous êtes déjà amis.";
    case "outgoing":
      return "Demande envoyée : elle attend sa réponse.";
    case "blocking":
    case "blocked":
      return "Ce lien d'ami n'est pas valide.";
    case "none":
    case "incoming":
      return null;
  }
}

function invalidLink() {
  return (
    <div className="mx-auto max-w-lg py-16 text-center">
      <p className="mb-2 font-heading text-xl font-bold text-foreground">Lien d&apos;ami invalide</p>
      <p className="mb-6 text-muted-foreground">Ce lien n&apos;existe pas ou a été remplacé par son propriétaire.</p>
      <Link className={buttonVariants({ variant: "secondary" })} href="/compte/amis">
        Mes amis
      </Link>
    </div>
  );
}

/** `/ami/{code}`: the member behind a scanned QR code, and « Ajouter en ami ». */
export function FriendLinkPage({ code }: { code: string }) {
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const queryKey = ["community-friend-link-view", code];

  const { data } = useQuery({
    queryKey,
    queryFn: () => fetchFriendLink(code),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  if (data === undefined) {
    return (
      <div className="flex min-h-[50vh] items-center justify-center">
        <Loader2 aria-hidden className="size-8 animate-spin text-muted-foreground" />
      </div>
    );
  }
  if (data.kind === "invalid") return invalidLink();
  if (data.kind === "error") {
    return <p className="py-16 text-center text-muted-foreground">Impossible de charger ce lien d&apos;ami.</p>;
  }

  const { member, relationship } = data.view;

  async function add() {
    setBusy(true);
    setError(null);
    const next = await addFriendFromLink(code);
    setBusy(false);
    if (next === null) {
      setError("Impossible d'envoyer la demande.");
      return;
    }
    queryClient.setQueryData(queryKey, { kind: "ok", view: { member, relationship: next } satisfies FriendLinkView });
    void queryClient.invalidateQueries({ queryKey: ["community-friends"] });
  }

  const status = friendLinkStatus(relationship.state);

  return (
    <div className="mx-auto max-w-md py-12">
      <div className="grid gap-6 rounded-lg border border-border bg-surface p-6">
        <p className="text-center text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">Lien d&apos;ami</p>
        <div className="flex justify-center">
          <FriendIdentity card={member} link />
        </div>
        {status === null ? (
          <button className={buttonVariants({ variant: "primary" })} disabled={busy} onClick={() => void add()} type="button">
            <UserPlus aria-hidden className="size-4" />
            {relationship.state === "incoming" ? "Accepter sa demande d'ami" : "Ajouter en ami"}
          </button>
        ) : (
          <p className="text-center text-sm text-muted-foreground">{status}</p>
        )}
        {error !== null ? <p className="text-center text-xs text-danger">{error}</p> : null}
      </div>
    </div>
  );
}
