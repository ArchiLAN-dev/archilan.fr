"use client";

import { useQueryClient, useQuery } from "@tanstack/react-query";
import { FlaskConical } from "lucide-react";
import { useCallback, useState } from "react";

import {
  fetchTrackerPacks,
  reportTrackerCompatibility,
  trackerPacksQueryKey,
  type AdminTrackerPack,
  type CompatibilityReport,
} from "@/features/admin/admin-tracker-packs-api";
import type { GameTrackerPack } from "@/features/trackers/tracker-packs-api";

import { TrackerScreen } from "./tracker-screen";
import type { TrackerSessionDeps } from "./use-tracker-session";

/**
 * Story 54.8: an admin tries a pack version on the site's tracker, offered to players or not. The version is read
 * from the admin API (a player gets nothing), and the load of each variant is reported on the game's admin page.
 */
type Props = {
  gameId: string;
  gameName: string;
  gameSlug: string;
  packId: string | null;
  versionId: string | null;
  initialVariantUid: string | null;
  /** Tests replace the API calls. */
  fetchPacks?: (gameId: string) => Promise<AdminTrackerPack[] | null>;
  sendReport?: (versionId: string, report: CompatibilityReport) => Promise<boolean>;
  deps?: TrackerSessionDeps;
};

/** The pack as the tracker screen takes it, on the version tried. Null when the pack does not keep that version. */
export function trialPack(pack: AdminTrackerPack, versionId: string): GameTrackerPack | null {
  const version = pack.versions.find((candidate) => candidate.id === versionId);
  if (version === undefined) return null;
  return {
    id: pack.id,
    name: pack.name,
    author: pack.author,
    licenseSpdx: pack.licenseSpdx,
    sourceUrl: pack.sourceUrl,
    isDefault: true,
    hasApVariant: version.variants.some((variant) => variant.flags.includes("ap")),
    defaultVariantUid: pack.defaultVariantUid,
    version: {
      id: version.id,
      packageVersion: version.packageVersion,
      rootPath: version.rootPath,
      sizeBytes: version.sizeBytes,
      sha256: version.sha256,
      variants: version.variants,
    },
  };
}

const STALE_MS = 60_000;

export function TrackerTrial({
  gameId,
  gameName,
  gameSlug,
  packId,
  versionId,
  initialVariantUid,
  fetchPacks = fetchTrackerPacks,
  sendReport = reportTrackerCompatibility,
  deps,
}: Props) {
  const queryClient = useQueryClient();
  const packsQuery = useQuery({ queryKey: trackerPacksQueryKey(gameId), queryFn: () => fetchPacks(gameId), staleTime: STALE_MS });
  const [lastReport, setLastReport] = useState<string | null>(null);

  const onLoadReport = useCallback(
    (report: CompatibilityReport) => {
      if (versionId === null) return;
      void sendReport(versionId, report).then((kept) => {
        setLastReport(kept ? "État de cette variante enregistré sur la fiche du jeu." : "L'état de cette variante n'a pas pu être enregistré.");
        if (kept) void queryClient.invalidateQueries({ queryKey: trackerPacksQueryKey(gameId) });
      });
    },
    [gameId, queryClient, sendReport, versionId],
  );

  if (packsQuery.isPending) return <p className="text-muted-foreground">Chargement de l&apos;essai…</p>;
  const packs = packsQuery.data;
  if (packs === null || packs === undefined) {
    return <p className="text-muted-foreground">L&apos;essai d&apos;un pack est réservé aux admins connectés.</p>;
  }
  const admin = packs.find((candidate) => candidate.id === packId);
  const pack = admin === undefined || versionId === null ? null : trialPack(admin, versionId);
  if (admin === undefined || pack === null) {
    return <p className="text-muted-foreground">Ce pack, ou cette version, n&apos;existe plus.</p>;
  }

  return (
    <div className="grid gap-4">
      <div className="flex flex-wrap items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-sm text-foreground" role="status">
        <FlaskConical aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
        <p>
          Essai de « {admin.name} »{pack.version.packageVersion !== null ? ` v${pack.version.packageVersion}` : ""}
          {admin.enabled ? "" : ", pas encore proposé aux joueurs"}. Chaque variante chargée met à jour son état sur la fiche
          admin du jeu.
          {lastReport !== null ? ` ${lastReport}` : ""}
        </p>
      </div>
      <TrackerScreen
        gameName={gameName}
        gameSlug={gameSlug}
        initialPackId={pack.id}
        initialVariantUid={initialVariantUid}
        onLoadReport={onLoadReport}
        packs={[pack]}
        {...(deps === undefined ? {} : { deps })}
      />
    </div>
  );
}
