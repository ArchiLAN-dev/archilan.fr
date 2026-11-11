"use client";

import Link from "next/link";
import { useQuery } from "@tanstack/react-query";
import { Gamepad2, Shovel } from "lucide-react";

import { ConnectionFields } from "@/components/connection-fields";
import { fetchSessionConnection, type SessionConnectionResult } from "@/features/events/events-api";
import { EventSurveyBanner } from "@/features/surveys/event-survey-banner";
import { PasseurNoticeCard } from "@/features/passeur/passeur-notice";
import { RegistrationPresenceCard } from "@/features/presence/presence-editor";
import { RegistrationBroadcastConsentCard } from "@/features/streaming/broadcast-consent-card";
import { BlindTestJoinButton } from "@/features/wallet/blind-test-join-button";
import { MinimapperzCodeCard } from "@/features/wallet/minimapperz-code-card";
import { fetchMyWallet, type Wallet } from "@/features/wallet/wallet-api";
import { DEFAULT_STALE_TIME } from "@/lib/query-client";

type Props = {
  eventId: string;
  eventSlug: string;
  registrationId: string;
  confirmed: boolean;
};

/**
 * Story 53.3: every tool of the event in one place. The existing cards read their own endpoint and render nothing while
 * their tool is off (blind test closed, no Minimapperz link, no survey), so the grid never shows an empty frame.
 */
export function RegistrantHubTools({ eventId, eventSlug, registrationId, confirmed }: Props) {
  const { data: connection } = useQuery({
    queryKey: ["registrant-hub-session", registrationId],
    queryFn: () => fetchSessionConnection(registrationId),
    // The game comes with a confirmed registration only.
    enabled: confirmed,
    staleTime: 30_000,
    refetchInterval: 60_000,
    retry: false,
  });
  const { data: wallet } = useQuery({
    queryKey: ["registrant-hub-wallet"],
    queryFn: () => fetchMyWallet(1),
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
  });

  const sessionPath = `/evenements/${eventSlug}/inscription/${registrationId}/session`;
  const runningSessionId = connection?.kind === "success" && connection.data.session?.status === "running" ? connection.data.session.id : null;

  return (
    <div className="grid gap-4">
      {confirmed ? <HubSessionCard result={connection ?? null} sessionPath={sessionPath} /> : null}
      {/* Story 52.5: what the Passeur opened while the member was away, during their game. */}
      {runningSessionId !== null ? <PasseurNoticeCard sessionId={runningSessionId} /> : null}
      <BlindTestJoinButton eventId={eventId} />
      <MinimapperzCodeCard eventId={eventId} />
      <EventSurveyBanner eventId={eventId} eventSlug={eventSlug} />
      <HubPellesCard eventId={eventId} wallet={wallet ?? null} />
      <div className="scroll-mt-24" id="presence">
        <RegistrationPresenceCard registrationId={registrationId} />
      </div>
      <RegistrationBroadcastConsentCard registrationId={registrationId} />
    </div>
  );
}

/** Same list as the session page: the game is being built, no address yet. */
const PRE_LAUNCH_STATUSES = ["draft", "validating", "ready", "generating", "generated", "launching"];

/** Story 53.3: where the member's game stands, with the address to copy once it runs. */
export function HubSessionCard({ result, sessionPath }: { result: SessionConnectionResult | null; sessionPath: string }) {
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
    </div>
  );
}

/** Story 53.3: the member's gold pelles and the ones of this event, with the way to the history. */
export function HubPellesCard({ eventId, wallet }: { eventId: string; wallet: Wallet | null }) {
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
    </div>
  );
}
