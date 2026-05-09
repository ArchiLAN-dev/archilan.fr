"use client";

import { AlertTriangle, Loader2, RefreshCw } from "lucide-react";
import { useEffect, useRef, useState } from "react";

const LOAD_TIMEOUT_MS = 15_000;

type IframeStatus = "loading" | "ready" | "timeout";

export function HelloAssoIframe({ src, title }: { src: string; title: string }) {
  return <HelloAssoIframeFrame key={src} src={src} title={title} />;
}

function HelloAssoIframeFrame({ src, title }: { src: string; title: string }) {
  const [status, setStatus] = useState<IframeStatus>("loading");
  const [retryKey, setRetryKey] = useState(0);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    timerRef.current = setTimeout(() => {
      setStatus((s) => (s === "loading" ? "timeout" : s));
    }, LOAD_TIMEOUT_MS);

    return () => {
      if (timerRef.current !== null) clearTimeout(timerRef.current);
    };
  }, [src, retryKey]);

  function retry() {
    setStatus("loading");
    setRetryKey((k) => k + 1);
  }

  return (
    <div className="relative overflow-hidden rounded border border-border">
      {status === "loading" ? (
        <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 bg-surface">
          <Loader2 aria-hidden="true" className="size-6 animate-spin text-muted-foreground" />
          <p className="text-sm text-muted-foreground">Chargement du formulaire HelloAsso…</p>
        </div>
      ) : null}

      {status === "timeout" ? (
        <div className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-4 bg-surface/95 p-6 text-center">
          <AlertTriangle aria-hidden="true" className="size-8 text-accent-warm" />
          <div>
            <p className="font-heading text-base font-semibold text-foreground">
              HelloAsso semble indisponible
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
              Le formulaire n&apos;a pas pu se charger. Vérifie ta connexion ou réessaie dans quelques instants.
            </p>
          </div>
          <button
            className="inline-flex items-center gap-2 rounded border border-border bg-background px-3 py-1.5 text-sm font-semibold text-foreground hover:border-accent"
            onClick={retry}
            type="button"
          >
            <RefreshCw aria-hidden="true" className="size-4" />
            Réessayer
          </button>
        </div>
      ) : null}

      <iframe
        allow="payment"
        className="w-full"
        height="750"
        key={retryKey}
        onLoad={() => {
          if (timerRef.current !== null) clearTimeout(timerRef.current);
          setStatus("ready");
        }}
        src={src}
        title={title}
      />
    </div>
  );
}
