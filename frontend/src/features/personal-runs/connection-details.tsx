"use client";

import { Server } from "lucide-react";

import { ConnectionFields } from "@/components/connection-fields";

export function ConnectionDetails({
  host,
  port,
  uri,
  password,
  adminPassword,
}: {
  host: string;
  port: number;
  uri: string | null;
  /** Null when the run was launched without a join password (story 16.13) - the row is then omitted. */
  password: string | null;
  adminPassword?: string | null;
}) {
  return (
    <div className="min-w-0 rounded-lg border border-[color:var(--color-success)]/30 bg-[color:var(--color-success)]/5 p-4">
      <div className="mb-1 flex items-center gap-2">
        <Server aria-hidden className="size-4 text-[color:var(--color-success)]" />
        <h3 className="text-sm font-semibold text-foreground">Infos de connexion</h3>
      </div>
      <p className="mb-3 text-xs text-muted-foreground">
        Valeurs masquées pour le stream - la copie fonctionne sans les afficher.
      </p>
      <ConnectionFields
        adminPassword={adminPassword}
        host={host}
        password={password}
        port={port}
        uri={uri}
      />
    </div>
  );
}
