import type { Metadata } from "next";
import { SignupForm } from "@/features/auth/signup-form";

export const metadata: Metadata = {
  title: "Inscription",
  description: "Crée ton compte ArchiLAN pour t'inscrire aux prochains événements.",
};

export default function SignupPage() {
  return (
    <div className="flex min-h-[60vh] flex-col items-center justify-center">
      <div className="w-full max-w-md">
        <div className="mb-8">
          <p className="mb-3 text-sm font-semibold uppercase tracking-[0.18em] text-accent-text">
            Rejoindre ArchiLAN
          </p>
          <h1 className="font-heading text-3xl font-bold text-foreground">
            Créer ton compte
          </h1>
          <p className="mt-3 text-base leading-7 text-muted-foreground">
            Rejoins la communauté et inscris-toi aux prochains événements Archipelago.
          </p>
        </div>

        <SignupForm />
      </div>
    </div>
  );
}
