export function PasswordResetBanner() {
  return (
    <div
      className="mb-5 rounded border border-[color:var(--color-success)]/50 bg-background p-3 text-sm text-[color:var(--color-success)]"
      role="status"
    >
      Mot de passe mis à jour. Tu peux maintenant te connecter avec ton nouveau mot de passe.
    </div>
  );
}
