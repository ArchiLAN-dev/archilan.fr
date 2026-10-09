import { hasNumberProp, hasStringProp } from "@/lib/type-guards";

import { nameColor } from "./name-colors";
import type { NotificationItem } from "./notifications-api";
import { TITLE_ICONS, TITLE_RARITIES, type TitleIcon, type TitleRarity } from "./profile-title-catalog";

/** Story 30.48: the family a notification belongs to, which sets the colour of its label and icon. */
export type NotificationTone = "reward" | "pelles" | "social" | "run" | "action" | "moderation";

export type NotificationIcon = "pelles" | "quests" | "unlock" | "alert" | "shield" | "trophy" | "puzzle" | "comment" | "heart" | "friend" | "bell";

/** A piece of the notification's sentence; the names stand out. */
export type Segment = { text: string; strong?: boolean };

export type NotificationVisual =
  | { kind: "actors" }
  | { kind: "achievement"; imageUrl: string | null }
  | { kind: "cosmetic"; cosmeticType: string; key: string; image: string | null }
  | { kind: "icon"; icon: NotificationIcon };

export type NotificationContent = {
  label: string;
  tone: NotificationTone;
  visual: NotificationVisual;
  title: Segment[];
  detail: string | null;
  /** Pelles credited (positive) or taken (negative), shown as a signed pill. */
  amount: number | null;
  /** A title won, drawn at its rarity. */
  titleBadge: { label: string; rarity: TitleRarity; icon: TitleIcon | null } | null;
  /** A friend request still waiting: it can be answered from the notification. */
  friendRequestId: string | null;
  /** A cosmetic won: a shortcut to put it on. */
  wearable: boolean;
};

const COSMETIC_KINDS: Record<string, string> = { frame: "Cadre", banner: "Bannière", title: "Titre", color: "Couleur de pseudo" };

function text(data: Record<string, unknown> | null | undefined, field: string): string {
  return data && hasStringProp(data, field) ? data[field] : "";
}

function isRecord(v: unknown): v is Record<string, unknown> {
  return typeof v === "object" && v !== null && !Array.isArray(v);
}

function detailsOf(item: NotificationItem, field: string): Record<string, unknown> | null {
  const value = item.details?.[field];
  return isRecord(value) ? value : null;
}

function actorName(item: NotificationItem): string {
  return item.actor?.displayName ?? item.actor?.slug ?? "Quelqu'un";
}

function base(tone: NotificationTone, label: string, visual: NotificationVisual, title: Segment[]): NotificationContent {
  return { label, tone, visual, title, detail: null, amount: null, titleBadge: null, friendRequestId: null, wearable: false };
}

const icon = (name: NotificationIcon): NotificationVisual => ({ kind: "icon", icon: name });
const strong = (value: string): Segment => ({ text: value, strong: true });

/**
 * Story 43.11b: what a starred friend did. `others`: more starred friends launched the same session or reached a goal
 * in it together, gathered in one alert.
 */
export function friendActivityTitle(data: Record<string, unknown> | null | undefined, actor: string): Segment[] {
  const title = text(data, "title");
  const others = data && hasNumberProp(data, "others") ? data.others : 0;
  const subject: Segment[] = others > 0 ? [strong(actor), { text: ` et ${others} ${others > 1 ? "autres favoris" : "autre favori"}` }] : [strong(actor)];
  const plural = others > 0;
  switch (text(data, "kind")) {
    case "registered":
      return [...subject, ...(title !== "" ? [{ text: " s'inscrit à " }, strong(title)] : [{ text: " s'inscrit à un événement" }])];
    case "session_started":
      return [...subject, ...(title !== "" ? [{ text: plural ? " lancent " : " lance " }, strong(title)] : [{ text: plural ? " lancent une partie" : " lance une partie" }])];
    default:
      return [
        ...subject,
        { text: plural ? " ont atteint leur objectif" : " a atteint son objectif" },
        ...(title !== "" ? [{ text: " dans " }, strong(title)] : []),
      ];
  }
}

/**
 * What the bell shows for one notification (story 30.48). `fallback` is the plain sentence of `messageFor`, kept for
 * a type this function does not know.
 */
export function contentFor(item: NotificationItem, fallback: string): NotificationContent {
  const data = item.data;
  switch (item.type) {
    case "friend_request_received": {
      const content = base("social", "Ami", { kind: "actors" }, [strong(actorName(item)), { text: " t'a envoyé une demande d'ami" }]);
      return { ...content, friendRequestId: text(detailsOf(item, "friendRequest"), "id") || null };
    }
    case "friend_request_accepted":
      return base("social", "Ami", { kind: "actors" }, [strong(actorName(item)), { text: " a accepté ta demande d'ami" }]);
    case "run_invitation": {
      // Story 43.1: a friend invites the member into a personal run.
      const run = text(data, "runTitle");
      return base("run", "Invitation", { kind: "actors" }, [
        strong(actorName(item)),
        ...(run !== "" ? [{ text: " t'invite dans " }, strong(run)] : [{ text: " t'invite dans sa partie" }]),
      ]);
    }
    case "friend_activity":
      return base("social", "Favori", { kind: "actors" }, friendActivityTitle(data, actorName(item)));
    case "comment_received":
      return base("social", "Commentaire", { kind: "actors" }, [strong(actorName(item)), { text: " a commenté ton profil" }]);
    case "kudos_received":
      return base("social", "Kudos", { kind: "actors" }, [
        strong(actorName(item)),
        { text: text(data, "targetType") === "achievement" ? " a aimé un de tes succès" : " a aimé une de tes runs" },
      ]);
    case "achievement_unlocked": {
      const achievement = detailsOf(item, "achievement");
      const name = text(achievement, "name");
      const content = base("reward", "Succès débloqué", { kind: "achievement", imageUrl: text(achievement, "imageUrl") || null }, name !== "" ? [strong(name)] : [{ text: "Nouveau succès débloqué" }]);
      return { ...content, detail: text(achievement, "description") || null };
    }
    case "cosmetic_unlocked":
      return cosmeticContent(item);
    case "collection_completed": {
      // Story 30.52: every achievement of a collection unlocked, and what the collection gave.
      const name = text(data, "name");
      const pelles = hasNumberProp(data, "pelles") ? data.pelles : 0;
      const cosmetic = text(data, "cosmetic");
      return {
        ...base("reward", "Collection complète", icon("trophy"), name !== "" ? [{ text: "Collection " }, strong(name), { text: " complète" }] : [{ text: "Collection complète" }]),
        amount: pelles > 0 ? pelles : null,
        detail: cosmetic !== "" ? `Gagné : ${cosmetic}` : null,
      };
    }
    case "pelles_adjusted": {
      const amount = hasNumberProp(data, "amount") ? data.amount : 0;
      const event = text(data, "eventTitle");
      const title: Segment[] =
        amount < 0 ? [{ text: "Pelles retirées par l'équipe" }] : event !== "" ? [{ text: "Pelles pour " }, strong(event)] : [{ text: "Pelles reçues" }];
      const reason = text(data, "reason");
      return { ...base("pelles", "Pelles", icon("pelles"), title), amount, detail: reason !== "" ? `« ${reason} »` : null };
    }
    case "quests_renewed": {
      const count = hasNumberProp(data, "count") ? data.count : 0;
      const max = hasNumberProp(data, "maxPelles") ? data.maxPelles : 0;
      const parts = [count > 0 ? `${count} ${count > 1 ? "quêtes" : "quête"}` : "", max > 0 ? `jusqu'à ${max} pelles` : ""].filter((p) => p !== "");
      return { ...base("reward", "Quêtes", icon("quests"), [strong("Nouvelles quêtes de la semaine")]), detail: parts.length > 0 ? `${parts.join(", ")}.` : null };
    }
    case "moderation_warning":
      return { ...base("moderation", "Modération", icon("shield"), [strong("Avertissement"), { text: " de la modération" }]), detail: text(data, "reason") || null };
    case "moderation_reply":
      return base("moderation", "Modération", icon("shield"), [strong("La modération"), { text: " t'a répondu" }]);
    case "account_flagged": {
      const name = text(data, "displayName");
      return base("moderation", "Modération", icon("shield"), name !== "" ? [{ text: "Compte à examiner : " }, strong(name)] : [{ text: "Un compte a atteint le seuil de modération" }]);
    }
    case "slot_unblocked": {
      const run = text(data, "runTitle");
      const slot = text(data, "slotName");
      const count = hasNumberProp(data, "reachableNow") ? data.reachableNow : null;
      const detail = [slot !== "" ? `Slot ${slot}` : "", count === null ? "" : `${count} ${count > 1 ? "checks accessibles" : "check accessible"}`].filter((p) => p !== "");
      return {
        ...base("run", "Partie", icon("unlock"), run !== "" ? [{ text: "Tu n'es plus bloqué dans " }, strong(run)] : [{ text: "Tu n'es plus bloqué dans ta partie" }]),
        detail: detail.length > 0 ? detail.join(" · ") : null,
      };
    }
    case "generation_failed": {
      const run = text(data, "runTitle");
      const slot = text(data, "slotName");
      const title: Segment[] = run !== "" ? [{ text: "La génération de " }, strong(run), { text: " a échoué" }] : [{ text: "La génération de la partie a échoué" }];
      const mine = text(data, "role") === "player" && slot !== "";
      return { ...base("action", "Action requise", icon("alert"), title), detail: mine ? `Ta config (slot « ${slot} ») est en cause.` : null };
    }
    case "apworld_incident_opened": {
      const game = text(data, "gameName");
      const sentence = fallback.split(" : ")[0] ?? fallback;
      return base("action", "Action requise", icon("puzzle"), game !== "" ? [{ text: `${sentence} : ` }, strong(game)] : [{ text: fallback }]);
    }
    case "slot_yaml_needs_review": {
      const game = text(data, "gameName") || "Un jeu";
      const where = text(data, "runTitle") || text(data, "eventTitle");
      return { ...base("action", "Action requise", icon("alert"), [strong(game), { text: " a changé de version" }]), detail: `Ton YAML est à revoir${where !== "" ? ` (« ${where} »)` : ""}.` };
    }
    default:
      return base("social", "Notification", icon("bell"), [{ text: fallback }]);
  }
}

function cosmeticContent(item: NotificationItem): NotificationContent {
  const data = item.data;
  const type = text(data, "type");
  const key = text(data, "key");
  const preview = detailsOf(item, "cosmetic");
  const stored = text(data, "label").match(/« (.+) »/)?.[1] ?? text(data, "label");
  const name = text(preview, "name") || (type === "color" ? (nameColor(key)?.label ?? stored) : stored) || "Un cosmétique";
  const kind = COSMETIC_KINDS[type] ?? "Cosmétique";
  const sourceLabel = text(data, "sourceLabel");
  const source = ({ quest: "la quête", collection: "la collection" } as Record<string, string>)[text(data, "source")] ?? "le succès";
  const rarity = TITLE_RARITIES.find((r) => r === text(preview, "rarity"));
  const titleIcon = text(preview, "icon");
  const badge = type === "title" && rarity !== undefined ? { label: name, rarity, icon: TITLE_ICONS.find((i) => i === titleIcon) ?? null } : null;

  return {
    ...base("reward", "Récompense", { kind: "cosmetic", cosmeticType: type, key, image: text(preview, "image") || null }, badge ? [{ text: `${kind} débloqué` }] : [{ text: `${kind} ` }, strong(name), { text: " débloqué" }]),
    detail: sourceLabel !== "" ? `Gagné avec ${source} « ${sourceLabel} »` : null,
    titleBadge: badge,
    wearable: true,
  };
}

/** A row of the bell: one notification, or the kudos of one day gathered (story 30.48). */
export type NotificationRowGroup = { key: string; items: NotificationItem[] };
export type NotificationSection = { label: string; rows: NotificationRowGroup[] };

function dayStart(date: Date): number {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
}

function daysAgo(iso: string, now: Date): number {
  const at = new Date(iso);
  if (Number.isNaN(at.getTime())) return 0;
  return Math.round((dayStart(now) - dayStart(at)) / 86_400_000);
}

function sectionLabel(days: number): string {
  if (days <= 0) return "Aujourd'hui";
  if (days === 1) return "Hier";
  if (days < 7) return "Cette semaine";
  return "Plus ancien";
}

/** The notifications by period, newest first, the kudos of a same day on one row. */
export function sectionsOf(items: NotificationItem[], now: Date): NotificationSection[] {
  const sections: NotificationSection[] = [];
  const kudosRows = new Map<string, NotificationRowGroup>();
  for (const item of items) {
    const days = daysAgo(item.createdAt, now);
    const label = sectionLabel(days);
    let section = sections.at(-1);
    if (section?.label !== label) {
      section = { label, rows: [] };
      sections.push(section);
    }
    if (item.type === "kudos_received") {
      const day = `${label}:${days}`;
      const row = kudosRows.get(day);
      if (row) {
        row.items.push(item);
        continue;
      }
      const fresh = { key: item.id, items: [item] };
      kudosRows.set(day, fresh);
      section.rows.push(fresh);
      continue;
    }
    section.rows.push({ key: item.id, items: [item] });
  }
  return sections;
}

/** The sentence of a row of several kudos: « A, B et 2 autres t'ont envoyé un kudos ». */
export function kudosTitle(items: NotificationItem[]): Segment[] {
  const names = [...new Set(items.map((i) => i.actor?.displayName ?? i.actor?.slug ?? "Quelqu'un"))];
  if (names.length === 1) return [strong(names[0] ?? "Quelqu'un"), { text: items.length > 1 ? ` t'a envoyé ${items.length} kudos` : " t'a envoyé un kudos" }];
  const shown = names.slice(0, 2);
  const others = names.length - shown.length;
  const segments: Segment[] = [strong(shown[0] ?? "")];
  if (others > 0) {
    segments.push({ text: ", " }, strong(shown[1] ?? ""), { text: ` et ${others} ${others > 1 ? "autres" : "autre"}` });
  } else {
    segments.push({ text: " et " }, strong(shown[1] ?? ""));
  }
  segments.push({ text: " t'ont envoyé un kudos" });
  return segments;
}

const WEEKDAY = new Intl.DateTimeFormat("fr-FR", { weekday: "long" });
const SHORT_DATE = new Intl.DateTimeFormat("fr-FR", { day: "numeric", month: "short" });

/** « il y a 5 min », « il y a 2 h », « hier », « lundi », « 3 oct. ». */
export function timeLabel(iso: string, now: Date): string {
  const at = new Date(iso);
  if (Number.isNaN(at.getTime())) return "";
  const days = daysAgo(iso, now);
  if (days <= 0) {
    const diff = now.getTime() - at.getTime();
    if (diff < 60_000) return "à l'instant";
    if (diff < 3_600_000) return `il y a ${Math.floor(diff / 60_000)} min`;
    return `il y a ${Math.floor(diff / 3_600_000)} h`;
  }
  if (days === 1) return "hier";
  if (days < 7) return WEEKDAY.format(at);
  return SHORT_DATE.format(at);
}
