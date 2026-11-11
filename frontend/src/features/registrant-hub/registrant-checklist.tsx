import Link from "next/link";
import { CheckCircle, Circle, CircleDot } from "lucide-react";

import type { ChecklistItem } from "./registrant-hub-api";

type Context = {
  eventSlug: string;
  registrationId: string;
};

type Line = {
  label: string;
  hint?: string;
  href: string;
};

/** Story 53.2: the wording and the link of a checklist line, from its key. Unknown keys are skipped. */
export function checklistLine(item: ChecklistItem, { eventSlug, registrationId }: Context): Line | null {
  const registration = `/evenements/${eventSlug}/inscription/${registrationId}`;
  const detail = item.detail ?? {};

  switch (item.key) {
    case "confirm":
      return { label: item.state === "done" ? "Inscription confirmée" : "Confirmer mon inscription", href: `${registration}/recap` };
    case "games":
      return { label: "Choisir mes jeux", href: `${registration}/jeux` };
    case "yaml": {
      const progress = typeof detail.done === "number" && typeof detail.total === "number" ? `${detail.done} sur ${detail.total}` : undefined;
      const firstSlot = typeof detail.firstSlotId === "string" ? detail.firstSlotId : null;
      return {
        label: "Régler les options de mes jeux",
        hint: progress,
        href: firstSlot === null ? `${registration}/recap` : `${registration}/slots/${firstSlot}`,
      };
    }
    case "registration_questions":
      return { label: "Répondre aux questions de l'orga", href: `${registration}/questions` };
    case "surveys": {
      const count = typeof detail.count === "number" ? detail.count : 1;
      // Story 53.7: the survey banner moved from the public page to the hub's tools.
      return { label: count > 1 ? `${count} sondages t'attendent` : "Un sondage t'attend", href: `/evenements/${eventSlug}/mon-event#outils` };
    }
    case "payment":
      return {
        label: item.state === "done" ? "Place payée" : "Payer ma place",
        hint:
          item.state === "done"
            ? undefined
            : "Déjà payé ? HelloAsso peut mettre une heure à nous le dire. Payé avec un autre email : préviens l'orga.",
        href: `/evenements/${eventSlug}#billetterie`,
      };
    case "presence":
      // Story 53.3: the presence card sits in the hub's tools.
      return { label: "Dire quand je suis là", hint: item.state === "done" ? undefined : "Facultatif", href: `/evenements/${eventSlug}/mon-event#presence` };
    case "discord":
      return { label: "Relier mon compte Discord", hint: item.state === "done" ? undefined : "Facultatif", href: "/compte/securite" };
    default:
      return null;
  }
}

type Props = Context & {
  items: ChecklistItem[];
  /** Story 53.6: folded behind its counter (during the event), like a ready list. */
  folded?: boolean;
};

/** Story 53.2: what the registrant still has to do; folded behind "Tu es prêt !" once every required step is done. */
export function RegistrantChecklist({ items, eventSlug, registrationId, folded = false }: Props) {
  const lines = items.flatMap((item) => {
    const line = checklistLine(item, { eventSlug, registrationId });
    return line === null ? [] : [{ item, line }];
  });
  if (lines.length === 0) return null;

  const required = lines.filter(({ item }) => item.required);
  const requiredDone = required.filter(({ item }) => item.state === "done").length;
  const ready = requiredDone === required.length;

  const list = (
    <ul className="grid gap-2">
      {lines.map(({ item, line }) => (
        <li key={item.key}>
          <ChecklistRow item={item} line={line} />
        </li>
      ))}
    </ul>
  );

  return (
    <div className="grid gap-3">
      <p className="text-sm text-muted-foreground">
        {ready ? "Tu es prêt !" : `${requiredDone} sur ${required.length} étapes faites`}
      </p>
      {ready || folded ? (
        <details className="group">
          <summary className="cursor-pointer text-sm text-accent-text hover:text-accent-text-hover">{ready ? "Revoir la liste" : "Voir la liste"}</summary>
          <div className="mt-3">{list}</div>
        </details>
      ) : (
        list
      )}
    </div>
  );
}

function ChecklistRow({ item, line }: { item: ChecklistItem; line: Line }) {
  const icon =
    item.state === "done" ? (
      <CheckCircle aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-success" />
    ) : item.state === "todo" ? (
      <CircleDot aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-warning" />
    ) : (
      <Circle aria-hidden="true" className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
    );
  const stateLabel = item.state === "done" ? "fait" : item.state === "todo" ? "à faire" : "facultatif";

  return (
    <div className="flex items-start gap-3 rounded border border-border p-3">
      {icon}
      <div className="grid gap-0.5">
        {item.state === "done" ? (
          <span className="text-sm text-muted-foreground">{line.label}</span>
        ) : (
          <Link className="text-sm font-semibold text-accent-text hover:text-accent-text-hover" href={line.href}>
            {line.label}
          </Link>
        )}
        <span className="sr-only">({stateLabel})</span>
        {line.hint ? <span className="text-xs text-muted-foreground">{line.hint}</span> : null}
      </div>
    </div>
  );
}
