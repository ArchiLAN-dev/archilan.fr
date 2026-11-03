import Link from "next/link";
import { AlertCircle, CheckCircle, Settings } from "lucide-react";

/**
 * Story 16.26: where a slot's options stand, shared by the event recap and the personal-run selection. A slot is
 * created with its game's default yaml, so holding a yaml does not mean the player configured anything: only a yaml
 * that differs from the default is "custom".
 */
export type SlotYamlStatus = "custom" | "default" | "required" | "none";

type SlotYamlSource = { playerYaml: string | null };
type GameYamlSource = { isApworldReady: boolean; defaultYaml: string | null };

export function resolveSlotYamlStatus(slot: SlotYamlSource, game: GameYamlSource | undefined): SlotYamlStatus {
  if (!game?.isApworldReady) return "none";
  if (slot.playerYaml === null || slot.playerYaml === "") return game.defaultYaml ? "default" : "required";
  if (slot.playerYaml === game.defaultYaml) return "default";
  return "custom";
}

/** A status, never an action: no border, no background, so nobody takes it for a button. */
export function SlotYamlStatusBadge({ status }: { status: SlotYamlStatus }) {
  if (status === "none") {
    return <span className="text-xs text-muted-foreground">-</span>;
  }
  if (status === "custom") {
    return (
      <span className="inline-flex items-center gap-1 text-xs font-medium text-success">
        <CheckCircle aria-hidden="true" className="size-3.5" />
        Personnalisé
      </span>
    );
  }
  if (status === "default") {
    return <span className="text-xs font-medium text-muted-foreground">Options par défaut</span>;
  }
  return (
    <span className="inline-flex items-center gap-1 text-xs font-medium text-accent-warm">
      <AlertCircle aria-hidden="true" className="size-3.5" />
      À configurer
    </span>
  );
}

/** The action that opens the slot's yaml editor, drawn as a button. */
export function ConfigureSlotYamlLink({ href, label }: { href: string; label: string }) {
  return (
    <Link
      aria-label={`Configurer les options de ${label}`}
      className="inline-flex shrink-0 items-center gap-1.5 rounded border border-border px-3 py-1.5 text-xs font-semibold text-foreground transition-colors hover:border-accent hover:text-accent-text"
      href={href}
    >
      <Settings aria-hidden="true" className="size-3.5" />
      Configurer
    </Link>
  );
}
