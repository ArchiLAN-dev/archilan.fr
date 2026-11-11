"use client";

import { use } from "react";
import { useQuery } from "@tanstack/react-query";
import { Download, Printer } from "lucide-react";

import { fetchAdminEventTitle } from "@/features/admin/admin-events-api";
import { env } from "@/lib/env";
import { qrPath } from "@/lib/qr-code";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import { registrantHubPath } from "./registrant-hub-api";

const QUIET_ZONE = 4;
/** Pixels per module of the downloaded PNG: about 1,000 px wide, sharp once printed. */
const PNG_MODULE_PX = 20;

/** Story 53.7: the full address of an event's registrant space, the one the QR code opens. */
export function hubUrl(eventId: string): string {
  return `${env.appUrl.replace(/\/$/, "")}${registrantHubPath(eventId)}`;
}

/** Story 53.7: the admin page that prints the QR code of the registrant space, for the LAN's tables. */
export function HubQrSheetPage({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params);
  const { data: title } = useQuery({
    queryKey: ["admin-event-title", eventId],
    queryFn: () => fetchAdminEventTitle(eventId),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  return <HubQrSheet eventTitle={title ?? null} url={hubUrl(eventId)} />;
}

type Props = {
  url: string;
  eventTitle: string | null;
};

/** Black on white whatever the theme: a phone camera reads contrast, and the sheet is printed. */
export function HubQrSheet({ url, eventTitle }: Props) {
  const { size, d } = qrPath(url);
  const box = size + QUIET_ZONE * 2;

  return (
    <div className="grid gap-6 px-6 py-6 md:px-12">
      <div className="grid gap-2">
        <h1 className="font-heading text-2xl font-semibold text-foreground">QR code de l&apos;espace</h1>
        <p className="max-w-2xl text-sm text-muted-foreground">
          À imprimer et poser sur les tables : il ouvre « Mon event » sur le téléphone d&apos;un inscrit, avec ce qu&apos;il lui
          reste à faire, ses codes et les infos pratiques. À épingler aussi sur Discord :{" "}
          <span className="break-all font-mono text-foreground">{url}</span>
        </p>
        <div className="flex flex-wrap gap-2">
          <button
            className="inline-flex min-h-10 items-center gap-2 rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
            onClick={() => window.print()}
            type="button"
          >
            <Printer aria-hidden className="size-4" />
            Imprimer
          </button>
          <button
            className="inline-flex min-h-10 items-center gap-2 rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
            onClick={() => downloadQrPng(url, eventTitle)}
            type="button"
          >
            <Download aria-hidden className="size-4" />
            Télécharger en PNG
          </button>
        </div>
      </div>

      {/* The sheet alone goes to the printer (globals.css, data-print-sheet), on an A5 page. */}
      <div
        className="mx-auto grid w-full max-w-md justify-items-center gap-5 rounded-xl border border-border bg-white p-8 text-center text-black"
        data-print-sheet=""
      >
        <p className="text-3xl font-bold">Perdu ? Scanne-moi</p>
        <svg aria-label="QR code de l'espace inscrit" className="w-full max-w-80" role="img" shapeRendering="crispEdges" viewBox={`0 0 ${box} ${box}`}>
          <rect fill="#ffffff" height={box} width={box} />
          <path d={d} fill="#000000" transform={`translate(${QUIET_ZONE} ${QUIET_ZONE})`} />
        </svg>
        {eventTitle ? <p className="text-xl font-semibold">{eventTitle}</p> : null}
        <p className="text-sm">Ce qu&apos;il te reste à faire, tes codes et les infos pratiques, au même endroit.</p>
      </div>
    </div>
  );
}

/** Draws the QR code (and the event's title under it) on a canvas, then saves it: no network call, no library. */
function downloadQrPng(url: string, eventTitle: string | null): void {
  const { size, d } = qrPath(url);
  const box = (size + QUIET_ZONE * 2) * PNG_MODULE_PX;
  const footer = eventTitle ? 120 : 0;
  const canvas = document.createElement("canvas");
  canvas.width = box;
  canvas.height = box + footer;
  const context = canvas.getContext("2d");
  if (context === null) return;

  context.fillStyle = "#ffffff";
  context.fillRect(0, 0, canvas.width, canvas.height);
  context.fillStyle = "#000000";
  context.save();
  context.translate(QUIET_ZONE * PNG_MODULE_PX, QUIET_ZONE * PNG_MODULE_PX);
  context.scale(PNG_MODULE_PX, PNG_MODULE_PX);
  context.fill(new Path2D(d));
  context.restore();

  if (eventTitle) {
    context.font = "bold 56px sans-serif";
    context.textAlign = "center";
    context.textBaseline = "middle";
    context.fillText(eventTitle, box / 2, box + footer / 2 - 20, box - 80);
  }

  const link = document.createElement("a");
  link.download = "qr-mon-event.png";
  link.href = canvas.toDataURL("image/png");
  link.click();
}
