import type { PublicPost, PublicPostType } from "./content-types";
import { externalLinks } from "@/lib/external-links";

const postTypeLabels: Record<PublicPostType, string> = {
  news: "Actualité",
  recap: "Récap",
  announcement: "Annonce",
};

// Public mock dataset only. Draft/unpublished posts are intentionally absent from this
// export so listing and detail routes cannot resolve unpublished content.
export const publicPosts: PublicPost[] = [
  {
    slug: "spring-sync-inscriptions",
    title: "Spring Sync ouvre les inscriptions",
    type: "announcement",
    status: "published",
    excerpt:
      "La prochaine session ouverte Archipelago arrive à Clermont-Ferrand avec une capacité limitée et un accompagnement pour les nouveaux joueurs.",
    coverImageUrl: "/images/events/lan-photo-1.webp",
    publishedAt: "25 avril 2026",
    publishedAtIso: "2026-04-25",
    readingTime: "3 min",
    relatedEventSlug: "spring-sync-2026",
    body: [
      "Spring Sync sera la prochaine session ouverte organisée par ArchiLAN autour d'Archipelago. L'objectif est de proposer une soirée accessible, lisible et suffisamment encadrée pour accueillir des joueurs qui découvrent encore le format.",
      "Les participants pourront préparer leurs jeux en amont, rejoindre la coordination communautaire et suivre les consignes pratiques depuis la page événement. La capacité reste volontairement limitée pour garder une bonne qualité d'accompagnement.",
      "Les informations définitives seront centralisées sur la page événement. Le Discord reste le meilleur endroit pour poser une question avant de rejoindre la session.",
    ],
  },
  {
    slug: "winter-link-recap",
    title: "Winter Link : retour sur une soirée multiworld dense",
    type: "recap",
    status: "published",
    excerpt:
      "36 joueurs, 14 jeux et une progression collective qui a montré pourquoi Archipelago fonctionne si bien en communauté.",
    coverImageUrl: "/images/events/lan-photo-1.webp",
    publishedAt: "10 décembre 2025",
    publishedAtIso: "2025-12-10",
    readingTime: "5 min",
    relatedEventSlug: "winter-link-2025",
    vodUrl: externalLinks.twitch,
    body: [
      "Winter Link a réuni 36 joueurs pour une session multiworld de six heures. La soirée a alterné entre déblocages rapides, longues chaînes d'items et moments de coordination où chaque équipe dépendait clairement des autres.",
      "Le format a confirmé l'intérêt d'avoir des consignes simples en amont et une communication Discord bien structurée pendant la partie. Les nouveaux joueurs ont pu comprendre rapidement le principe : un objet trouvé dans un jeu peut devenir le déclencheur décisif pour un autre monde.",
      "Ce récap servira aussi de base pour améliorer les prochaines sessions publiques : capacité annoncée plus tôt, rappels de configuration plus visibles et meilleure préparation des jeux les plus longs.",
    ],
  },
  {
    slug: "archilan-archipelago-france",
    title: "Pourquoi ArchiLAN structure une communauté Archipelago en France",
    type: "news",
    status: "published",
    excerpt:
      "ArchiLAN veut rendre les sessions Archipelago plus faciles à rejoindre, comprendre et organiser pour les joueurs francophones.",
    publishedAt: "28 novembre 2025",
    publishedAtIso: "2025-11-28",
    readingTime: "4 min",
    body: [
      "Archipelago est puissant, mais il peut être difficile à aborder sans contexte. Entre les jeux compatibles, les options de randomizer et la coordination entre joueurs, une première session peut sembler plus complexe qu'elle ne l'est vraiment.",
      "Le rôle d'ArchiLAN est de rendre cette expérience plus lisible : expliquer les bases, annoncer les événements clairement, préparer les inscriptions et garder une trace publique des moments importants.",
      "Le site archilan.fr servira progressivement de point d'entrée pour les événements, les récaps et les informations utiles à la communauté.",
    ],
  },
];

export function getPostTypeLabel(type: PublicPostType) {
  return postTypeLabels[type];
}

export function getPublicPostBySlug(slug: string) {
  return publicPosts.find((post) => post.status === "published" && post.slug === slug);
}
