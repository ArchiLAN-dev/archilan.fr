import { ExternalLink } from "lucide-react";

import type { GameStep } from "./public-games-api";

const STEP_TYPE_LABELS: Record<GameStep["type"], string> = {
  acquire: "Se procurer le jeu",
  apworld: "Apworld",
  client: "Client / patcher",
  yaml: "Configuration YAML",
  connect: "Connexion",
  note: "Note",
};

/**
 * Read-only render of an ordered list of install steps (story 31.1/31.3). Descriptions are plain
 * text (`whitespace-pre-line`, never raw HTML); links open in a new tab with `rel="noopener"`.
 * Shared by the game detail Installation section and the generic `/aide/archipelago` guide.
 */
export function InstallStepsView({ steps }: { steps: GameStep[] }) {
  return (
    <ol className="grid gap-4">
      {steps.map((step, index) => (
        <li className="grid gap-2 rounded-lg border border-border bg-surface p-4" key={index}>
          <div className="flex items-center gap-2">
            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-accent/15 text-xs font-semibold text-accent-text">
              {index + 1}
            </span>
            <span className="text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground">
              {STEP_TYPE_LABELS[step.type]}
            </span>
          </div>
          <h3 className="font-heading font-semibold leading-tight text-foreground">{step.title}</h3>
          {step.description ? (
            <p className="whitespace-pre-line text-sm leading-7 text-muted-foreground">{step.description}</p>
          ) : null}
          {step.links.length > 0 ? (
            <ul className="grid gap-1.5">
              {step.links.map((link, linkIndex) =>
                link.url !== null ? (
                  <li key={`${link.label}-${linkIndex}`}>
                    <a
                      className="inline-flex items-center gap-2 text-accent-text underline-offset-2 hover:underline"
                      href={link.url}
                      rel="noopener noreferrer"
                      target="_blank"
                    >
                      {link.label}
                      <ExternalLink aria-hidden="true" className="size-3.5" />
                    </a>
                  </li>
                ) : (
                  <li className="text-sm text-muted-foreground" key={`${link.label}-${linkIndex}`}>
                    {link.label}
                  </li>
                ),
              )}
            </ul>
          ) : null}
        </li>
      ))}
    </ol>
  );
}
