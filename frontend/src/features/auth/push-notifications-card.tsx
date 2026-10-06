"use client";

import { useCallback, useEffect, useState } from "react";
import { BellOff, BellRing, Loader2 } from "lucide-react";

import { fetchPushPublicKey, registerPushSubscription, removePushSubscription } from "./push-api";
import {
  cardState,
  detectPushSupport,
  pushContentEncoding,
  urlBase64ToUint8Array,
  type PushCardState,
  type PushPermission,
  type PushSupport,
} from "./push-support";

const SERVICE_WORKER_URL = "/sw.js";

type Feedback = { text: string; isError: boolean } | null;

/**
 * "Notifications sur cet appareil" (story 40.2): browser pushes, turned on and off device by device. The
 * browser's own permission is the master switch; this card asks for it and registers the device.
 */
export function PushNotificationsCard() {
  const [publicKey, setPublicKey] = useState<string | null | undefined>(undefined);
  const [support, setSupport] = useState<PushSupport>("unsupported");
  const [permission, setPermission] = useState<PushPermission>("default");
  const [subscription, setSubscription] = useState<PushSubscription | null>(null);
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<Feedback>(null);

  const refresh = useCallback(async () => {
    const detected = detectPushSupport({
      hasServiceWorker: "serviceWorker" in navigator,
      hasPushManager: "PushManager" in window,
      hasNotification: "Notification" in window,
      isIos: /iPad|iPhone|iPod/.test(navigator.userAgent),
      isStandalone: window.matchMedia("(display-mode: standalone)").matches,
    });
    setSupport(detected);
    if ("Notification" in window) setPermission(Notification.permission);

    if (detected === "supported") {
      const registration = await navigator.serviceWorker.getRegistration("/");
      setSubscription((await registration?.pushManager.getSubscription()) ?? null);
    }
    setPublicKey(await fetchPushPublicKey());
  }, []);

  useEffect(() => {
    // Browser APIs only exist client-side, and the key comes from the API: read both once mounted.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void refresh();
  }, [refresh]);

  async function enable(): Promise<void> {
    if (publicKey === null || publicKey === undefined) return;
    setBusy(true);
    setFeedback(null);
    try {
      const granted = await Notification.requestPermission();
      setPermission(granted);
      if (granted !== "granted") {
        setFeedback({ text: "Notifications refusées par le navigateur.", isError: true });
        return;
      }

      await navigator.serviceWorker.register(SERVICE_WORKER_URL, { scope: "/" });
      const registration = await navigator.serviceWorker.ready;
      const created = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey),
      });
      const ok = await registerPushSubscription(created.toJSON(), pushContentEncoding(PushManager.supportedContentEncodings));
      if (!ok) {
        await created.unsubscribe();
        setFeedback({ text: "Impossible d'activer les notifications pour le moment.", isError: true });
        return;
      }
      setSubscription(created);
      await registration.showNotification("ArchiLAN", {
        body: "Notifications activées sur cet appareil.",
        icon: "/images/logo.webp",
        tag: "push-enabled",
      });
      setFeedback({ text: "Notifications activées sur cet appareil.", isError: false });
    } catch {
      setFeedback({ text: "Impossible d'activer les notifications pour le moment.", isError: true });
    } finally {
      setBusy(false);
    }
  }

  async function disable(): Promise<void> {
    if (subscription === null) return;
    setBusy(true);
    setFeedback(null);
    const endpoint = subscription.endpoint;
    try {
      await subscription.unsubscribe();
    } catch {
      // The browser may already have dropped it; the site forgets it all the same.
    }
    await removePushSubscription(endpoint);
    setSubscription(null);
    setFeedback({ text: "Notifications désactivées sur cet appareil.", isError: false });
    setBusy(false);
  }

  if (publicKey === undefined) {
    return <div aria-hidden className="h-40 animate-pulse rounded-lg border border-border bg-surface" />;
  }

  return (
    <PushNotificationsCardView
      busy={busy}
      feedback={feedback}
      onDisable={() => void disable()}
      onEnable={() => void enable()}
      state={cardState({ publicKey, support, permission, subscribed: subscription !== null })}
    />
  );
}

type ViewProps = {
  state: PushCardState;
  busy: boolean;
  feedback: Feedback;
  onEnable: () => void;
  onDisable: () => void;
};

const BUTTON =
  "inline-flex min-h-9 items-center justify-center gap-2 rounded border border-border bg-surface px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-60";

export function PushNotificationsCardView({ state, busy, feedback, onEnable, onDisable }: ViewProps) {
  return (
    <section className="card-glow grid gap-4 rounded-lg border border-border p-6">
      <div>
        <h2 className="flex items-center gap-2 font-heading text-xl font-semibold text-foreground">
          {state === "enabled" ? <BellRing aria-hidden className="size-5 text-accent-text" /> : <BellOff aria-hidden className="size-5" />}
          Notifications sur cet appareil
        </h2>
        <p className="mt-2 text-sm leading-6 text-muted-foreground">
          Reçois une notification système, même site fermé, quand tu n&apos;es plus bloqué dans une partie privée.
          À activer sur chaque appareil ; tu peux les couper ici ou dans les réglages du navigateur.
        </p>
      </div>

      {feedback !== null ? (
        <p className="rounded border border-border bg-background p-3 text-sm text-muted-foreground" role={feedback.isError ? "alert" : "status"}>
          {feedback.text}
        </p>
      ) : null}

      <StateBody busy={busy} onDisable={onDisable} onEnable={onEnable} state={state} />
    </section>
  );
}

function StateBody({ state, busy, onEnable, onDisable }: Omit<ViewProps, "feedback">) {
  switch (state) {
    case "unavailable":
      return <p className="text-sm text-muted-foreground">Les notifications push ne sont pas encore disponibles sur ArchiLAN.</p>;
    case "unsupported":
      return <p className="text-sm text-muted-foreground">Ce navigateur ne prend pas en charge les notifications push.</p>;
    case "ios-install":
      // iOS only delivers pushes to a site installed as an app; ArchiLAN installs since story 40.4.
      return (
        <p className="text-sm text-muted-foreground">
          Sur iPhone et iPad, iOS réserve les notifications aux sites installés comme une application : dans Safari,
          touche le bouton Partager puis « Sur l&apos;écran d&apos;accueil », ouvre ArchiLAN depuis l&apos;icône
          ajoutée, et reviens ici pour les activer.
        </p>
      );
    case "blocked":
      return (
        <p className="text-sm text-muted-foreground">
          Les notifications sont bloquées pour ce site. Autorise-les dans les réglages du navigateur (icône à gauche de
          l&apos;adresse), puis recharge la page.
        </p>
      );
    case "enabled":
      return (
        <div className="flex flex-wrap items-center gap-4">
          <p className="text-sm font-semibold text-success">Activées sur cet appareil.</p>
          <button className={BUTTON} disabled={busy} onClick={onDisable} type="button">
            {busy ? <Loader2 aria-hidden className="size-4 animate-spin" /> : null}
            Désactiver
          </button>
        </div>
      );
    case "disabled":
      return (
        <div className="flex flex-wrap items-center gap-4">
          <button className={BUTTON} disabled={busy} onClick={onEnable} type="button">
            {busy ? <Loader2 aria-hidden className="size-4 animate-spin" /> : <BellRing aria-hidden className="size-4" />}
            Activer les notifications
          </button>
        </div>
      );
  }
}
