"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { ExternalLink, Gamepad2, Shovel } from "lucide-react";

import { ConnectionFields } from "@/components/connection-fields";
import { HelpLink } from "@/components/help-link";
import { fetchHelpGuides, HELP_GUIDE_SLUGS, helpGuidePath, type HelpGuideCard } from "@/features/content/help-guides-api";
import { fetchSessionConnection, type SessionConnectionResult } from "@/features/events/events-api";
import { EventSurveyBanner } from "@/features/surveys/event-survey-banner";
import { PasseurNoticeCard } from "@/features/passeur/passeur-notice";
import { RegistrationPresenceCard } from "@/features/presence/presence-editor";
import { RegistrationBroadcastConsentCard } from "@/features/streaming/broadcast-consent-card";
import { MediaPublishCard } from "@/features/streaming/media-publish-card";
import { BlindTestJoinButton } from "@/features/wallet/blind-test-join-button";
import { MinimapperzCodeCard } from "@/features/wallet/minimapperz-code-card";
import { fetchMyWallet, type Wallet } from "@/features/wallet/wallet-api";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";
import type { EventPhase } from "./event-phase";
import { FINISHED_SESSION_STATUSES, reliveLinks, type ReliveLink } from "./hub-sections";

type Props = {
  eventId: string;
  eventSlug: string;
  registrationId: string;
  confirmed: boolean;
  /** Story 53.6: after the event, only the surveys and the pelles stay. */
  phase?: EventPhase;
};

/** The member's game, read by the tools and by "Revivre l'event" under one key. */
function useHubSession(registrationId: string, confirmed: boolean) {
  return useQuery({
    queryKey: ["registrant-hub-session", registrationId],
    queryFn: () => fetchSessionConnection(registrationId),
    // The game comes with a confirmed registration only.
    enabled: confirmed,
    staleTime: 30_000,
    refetchInterval: 60_000,
    retry: false,
  });
}

/**
 * Story 53.3: every tool of the event in one place. The existing cards read their own endpoint and render nothing while
 * their tool is off (blind test closed, no Minimapperz link, no survey), so the grid never shows an empty frame.
 */
export function RegistrantHubTools({ eventId, eventSlug, registrationId, confirmed, phase = "before" }: Props) {
  const { data: connection } = useHubSession(registrationId, phase !== "after" && confirmed);
  const { data: wallet } = useQuery({
    queryKey: ["registrant-hub-wallet"],
    queryFn: () => fetchMyWallet(1),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  const { data: guides } = useQuery({
    queryKey: HELP_GUIDES_QUERY_KEY,
    queryFn: fetchHelpGuides,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });
  const help = (slug: string) => guideHref(guides ?? null, slug);

  const sessionPath = `/evenements/${eventSlug}/inscription/${registrationId}/session`;
  const runningSessionId = connection?.kind === "success" && connection.data.session?.status === "running" ? connection.data.session.id : null;

  if (phase === "after") {
    return (
      <div className="grid gap-4">
        <EventSurveyBanner eventId={eventId} eventSlug={eventSlug} />
        <HubPellesCard eventId={eventId} helpHref={help(HELP_GUIDE_SLUGS.pelles)} wallet={wallet ?? null} />
      </div>
    );
  }

  return (
    <div className="grid gap-4">
      {confirmed ? <HubSessionCard helpHref={help(HELP_GUIDE_SLUGS.firstGame)} result={connection ?? null} sessionPath={sessionPath} /> : null}
      {/* Story 52.5: what the Passeur opened while the member was away, during their game. */}
      {runningSessionId !== null ? <PasseurNoticeCard helpHref={help(HELP_GUIDE_SLUGS.passeur)} sessionId={runningSessionId} /> : null}
      <BlindTestJoinButton eventId={eventId} helpHref={help(HELP_GUIDE_SLUGS.blindTest)} />
      <MinimapperzCodeCard eventId={eventId} helpHref={help(HELP_GUIDE_SLUGS.minimapperz)} />
      <EventSurveyBanner eventId={eventId} eventSlug={eventSlug} />
      <HubPellesCard eventId={eventId} helpHref={help(HELP_GUIDE_SLUGS.pelles)} wallet={wallet ?? null} />
      <div className="scroll-mt-24" id="presence">
        <RegistrationPresenceCard registrationId={registrationId} />
      </div>
      <RegistrationBroadcastConsentCard helpHref={help(HELP_GUIDE_SLUGS.broadcast)} registrationId={registrationId} />
      {/* Story 47.7: the LAN media server, from the day before to the end, for a confirmed registrant who accepts the broadcast. */}
      {confirmed ? <MediaPublishCard helpHref={help(HELP_GUIDE_SLUGS.broadcast)} registrationId={registrationId} /> : null}
    </div>
  );
}

type ReliveProps = {
  eventSlug: string;
  registrationId: string;
  confirmed: boolean;
  vodUrl: string | null;
  hasPhotos: boolean;
};

/** Story 53.6: "Revivre l'event", with the recap of the member's game once it is over. */
export function HubRelive({ eventSlug, registrationId, confirmed, vodUrl, hasPhotos }: ReliveProps) {
  const { data: connection } = useHubSession(registrationId, confirmed);
  const session = connection?.kind === "success" ? connection.data.session : null;
  const recapSessionId = session !== null && FINISHED_SESSION_STATUSES.includes(session.status) ? session.id : null;

  return <ReliveLinks links={reliveLinks({ eventSlug, recapSessionId, vodUrl, hasPhotos })} />;
}

export function ReliveLinks({ links }: { links: ReliveLink[] }) {
  return (
    <ul className="grid gap-2 sm:grid-cols-2">
      {links.map((link) => (
        <li key={link.key}>
          {link.external ? (
            <a
              className="flex min-h-11 items-center justify-between gap-2 rounded-lg border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
              href={link.href}
              rel="noopener noreferrer"
              target="_blank"
            >
              {link.label}
              <ExternalLink aria-hidden className="size-4 text-muted-foreground" />
            </a>
          ) : (
            <Link
              className="flex min-h-11 items-center rounded-lg border border-border bg-background px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
              href={link.href}
            >
              {link.label}
            </Link>
          )}
        </li>
      ))}
    </ul>
  );
}

/** Shared with the guides section of the hub: one read of the published guides. */
export const HELP_GUIDES_QUERY_KEY = ["help-guides"] as const;

/** Story 53.5: the guide of a tool card, only once it is published (a draft or a deleted guide gives no link). */
export function guideHref(guides: readonly HelpGuideCard[] | null, slug: string): string | null {
  return guides?.some((guide) => guide.slug === slug) ? helpGuidePath(slug) : null;
}

/** Same list as the session page: the game is being built, no address yet. */
const PRE_LAUNCH_STATUSES = ["draft", "validating", "ready", "generating", "generated", "launching"];

/** Story 53.3: where the member's game stands, with the address to copy once it runs. */
export function HubSessionCard({
  result,
  sessionPath,
  helpHref = null,
}: {
  result: SessionConnectionResult | null;
  sessionPath: string;
  /** Story 53.5: the guide of a first game. */
  helpHref?: string | null;
}) {
  if (result === null || result.kind !== "success") return null;
  const { session, slots } = result.data;

  let body;
  if (session === null) {
    body = <p className="text-sm text-muted-foreground">Pas encore de partie : l&apos;orga la lance le jour J.</p>;
  } else if (session.status === "running" && session.host !== null && session.port !== null) {
    body = (
      <ConnectionFields
        host={session.host}
        password={session.password}
        port={session.port}
        slots={slots.map((slot) => ({ name: slot.slotName, game: slot.gameName }))}
        uri={session.connectionUri}
      />
    );
  } else if (session.status === "stopped" || session.status === "finished") {
    body = <p className="text-sm text-muted-foreground">Ta partie est terminée.</p>;
  } else if (session.status === "failed" || session.status === "crashed") {
    body = <p className="text-sm text-muted-foreground">Ta partie est interrompue, l&apos;orga est prévenue.</p>;
  } else if (PRE_LAUNCH_STATUSES.includes(session.status)) {
    body = <p className="text-sm text-muted-foreground">Ta partie se prépare, l&apos;adresse de connexion arrive ici.</p>;
  } else {
    body = <p className="text-sm text-muted-foreground">Ta partie est en pause.</p>;
  }

  return (
    <div className="grid gap-3 rounded-xl border border-border bg-background p-4">
      <p className="flex items-center gap-2 text-sm font-semibold text-foreground">
        <Gamepad2 aria-hidden className="size-4 text-accent-text" />
        Ma partie Archipelago
      </p>
      {body}
      <Link className="w-fit text-sm text-accent-text hover:text-accent-text-hover" href={sessionPath}>
        Détail de ma partie
      </Link>
      <HelpLink href={helpHref} />
    </div>
  );
}

/** Story 53.3: the member's gold pelles and the ones of this event, with the way to the history. */
export function HubPellesCard({ eventId, wallet, helpHref = null }: { eventId: string; wallet: Wallet | null; helpHref?: string | null }) {
  if (wallet === null) return null;
  const eventBalance = wallet.events.find((balance) => balance.eventId === eventId)?.balance ?? 0;

  return (
    <div className="grid gap-2 rounded-xl border border-border bg-background p-4">
      <p className="flex items-center gap-2 text-sm font-semibold text-foreground">
        <Shovel aria-hidden className="size-4 text-warning" />
        Mes pelles
      </p>
      <p className="text-sm text-muted-foreground">
        <span className="font-semibold text-foreground">{wallet.gold}</span> en or
        {eventBalance > 0 ? (
          <>
            {" "}
            et <span className="font-semibold text-foreground">{eventBalance}</span> pour cet événement
          </>
        ) : null}
      </p>
      <Link className="w-fit text-sm text-accent-text hover:text-accent-text-hover" href="/compte/pelles">
        Mes pelles et leur historique
      </Link>
      <HelpLink href={helpHref} />
    </div>
  );
}
