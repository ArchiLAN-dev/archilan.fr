import type { AccountMembership } from "@/features/payments/membership-api";

/**
 * Le libellé de rôle de l'espace compte. « Membre » suit l'adhésion active, comme les accès côté API
 * (story 22.7) : le rôle ROLE_MEMBER n'est jamais attribué par un paiement HelloAsso, et reste en place
 * après l'expiration - il ne dit rien de l'adhésion.
 */
export function accountRoleLabel(roles: string[], membershipStatus: AccountMembership["status"] | undefined): string {
  if (roles.includes("ROLE_ADMIN")) return "Admin";
  if (membershipStatus === "active") return "Membre";
  return "Utilisateur";
}
