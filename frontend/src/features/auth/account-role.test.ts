import { accountRoleLabel } from "./account-role";

/**
 * Story 22.7 : le libellé lisait le rôle ROLE_MEMBER, qu'un paiement HelloAsso n'attribue jamais. Un
 * membre qui venait de payer lisait « Utilisateur » alors que ses accès, eux, suivaient bien son
 * adhésion. Le libellé suit maintenant l'adhésion active, comme les accès.
 */
describe("accountRoleLabel", () => {
  test("un membre qui a payé est « Membre », sans rôle ROLE_MEMBER", () => {
    expect(accountRoleLabel(["ROLE_USER"], "active")).toBe("Membre");
  });

  test("une adhésion expirée n'est plus « Membre », même avec le rôle resté en place", () => {
    expect(accountRoleLabel(["ROLE_USER", "ROLE_MEMBER"], "expired")).toBe("Utilisateur");
  });

  test.each(["none", undefined] as const)("sans adhésion (%s), « Utilisateur »", (status) => {
    expect(accountRoleLabel(["ROLE_USER"], status)).toBe("Utilisateur");
  });

  test("un admin reste « Admin »", () => {
    expect(accountRoleLabel(["ROLE_USER", "ROLE_ADMIN"], "active")).toBe("Admin");
  });
});
