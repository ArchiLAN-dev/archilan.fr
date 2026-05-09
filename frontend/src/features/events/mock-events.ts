import type { PublicEvent } from "./event-types";
import { externalLinks } from "@/lib/external-links";

// Public mock dataset only. Draft/unpublished events are intentionally absent from this
// export so dynamic public routes cannot resolve them.
export const upcomingEvents: PublicEvent[] = [
  {
    id: "spring-sync-2026",
    title: "Spring Sync Archipelago",
    type: "Multiworld ouvert",
    date: "31 mai 2026",
    dateIso: "2026-05-31",
    endDateIso: "2026-05-31",
    location: "Clermont-Ferrand",
    description:
      "Une session multiworld ouverte aux joueurs qui veulent découvrir une soirée Archipelago en équipe, avec accompagnement pour les premières configurations.",
    coverImageUrl: "/images/events/lan-photo-1.webp",
    photoGallery: ["/images/events/lan-photo-1.webp", "/images/events/lan-photo-1.webp"],
    attendanceMode: "offline",
    status: "open",
    capacity: { remaining: 23, total: 48 },
  },
  {
    id: "summer-seed-2026",
    title: "Summer Seed Night",
    type: "Soirée découverte",
    date: "20 juin 2026",
    dateIso: "2026-06-20",
    endDateIso: "2026-06-20",
    location: "En ligne + Discord",
    description:
      "Une soirée découverte en ligne pensée pour expliquer les bases, tester quelques mondes courts et répondre aux questions avant les événements plus longs.",
    attendanceMode: "online",
    status: "upcoming",
  },
  {
    id: "member-race-2026",
    title: "Member Race",
    type: "Événement membres",
    date: "12 septembre 2026",
    dateIso: "2026-09-12",
    endDateIso: "2026-09-12",
    location: "Clermont-Ferrand",
    description:
      "Une course réservée aux membres ArchiLAN, avec seed préparée, coordination sur place et priorité aux habitués de l'association.",
    attendanceMode: "offline",
    status: "members-only",
    capacity: { remaining: 8, total: 24 },
  },
  {
    id: "full-chaos-2026",
    title: "Chaos Ladder",
    type: "Tournoi amical",
    date: "3 octobre 2026",
    dateIso: "2026-10-03",
    endDateIso: "2026-10-03",
    location: "Discord ArchiLAN",
    description:
      "Un tournoi amical à capacité limitée pour les joueurs déjà familiers avec Archipelago et prêts à gérer une seed plus chaotique.",
    attendanceMode: "online",
    status: "full",
    capacity: { remaining: 0, total: 32 },
  },
];

export const pastEvents: PublicEvent[] = [
  {
    id: "winter-link-2025",
    title: "Winter Link",
    type: "Récap public",
    date: "7 décembre 2025",
    dateIso: "2025-12-07",
    endDateIso: "2025-12-07",
    location: "Clermont-Ferrand",
    description:
      "Retour sur une grande session hivernale Archipelago, avec plusieurs jeux connectés, une progression collective et des moments marquants partagés.",
    coverImageUrl: "/images/events/lan-photo-1.webp",
    photoGallery: ["/images/events/lan-photo-1.webp", "/images/events/lan-photo-1.webp", "/images/events/lan-photo-1.webp"],
    attendanceMode: "offline",
    status: "completed",
    recapAvailable: true,
    recap:
      "Winter Link a réuni 36 joueurs autour de 14 jeux pendant une soirée de coordination, de découvertes croisées et de déblocages improbables.",
    vodUrl: externalLinks.twitch,
    stats: { players: 36, games: 14, duration: "6 h" },
  },
  {
    id: "first-seed-2025",
    title: "First Seed",
    type: "Archive",
    date: "14 septembre 2025",
    dateIso: "2025-09-14",
    endDateIso: "2025-09-14",
    location: "Discord ArchiLAN",
    description:
      "Première session publique utilisée pour valider le format communautaire et préparer les prochains rendez-vous ArchiLAN autour d'Archipelago.",
    attendanceMode: "online",
    status: "completed",
    recapAvailable: false,
    stats: { players: 18, games: 9, duration: "4 h" },
  },
];

export const publicEvents = [...upcomingEvents, ...pastEvents];

export function getPublicEventBySlug(slug: string) {
  return publicEvents.find((event) => event.id === slug);
}
