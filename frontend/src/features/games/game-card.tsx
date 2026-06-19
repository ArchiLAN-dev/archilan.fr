import Link from "next/link";
import { Gamepad2 } from "lucide-react";
import type { PublicGame } from "./public-games-api";

export const availabilityConfig = {
  available: { label: "Disponible", className: "border-success/50 bg-success/10 text-success" },
  experimental: { label: "Expérimental", className: "border-warning/50 bg-warning/10 text-warning" },
} as const;

export function GameCard({ game, owned = false }: { game: PublicGame; owned?: boolean }) {
  const status = availabilityConfig[game.availability] ?? availabilityConfig.available;

  return (
    <Link
      className="card-glow grid grid-rows-[auto_1fr] overflow-hidden rounded-lg border border-border transition-colors hover:border-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
      href={`/jeux/${game.slug}`}
    >
      <div className="relative aspect-[3/4] overflow-hidden bg-surface">
        {game.coverImageUrl ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            alt={game.coverImageAlt || game.name}
            className="h-full w-full object-cover transition-transform duration-300 hover:scale-105"
            loading="lazy"
            src={game.coverImageUrl}
          />
        ) : (
          <div className="flex h-full items-center justify-center bg-[linear-gradient(135deg,color-mix(in_oklab,var(--color-surface)_88%,var(--color-accent)),var(--color-background))]">
            <Gamepad2 aria-hidden="true" className="size-12 text-muted-foreground/40" />
          </div>
        )}
      </div>

      <div className="grid content-start gap-2 p-4">
        <div className="flex flex-wrap gap-1.5">
          <span className={`rounded border px-2 py-0.5 text-xs font-semibold ${status.className}`}>
            {status.label}
          </span>
          {owned ? (
            <span className="rounded border border-success/50 bg-success/10 px-2 py-0.5 text-xs font-semibold text-success">
              Tu possèdes ce jeu
            </span>
          ) : null}
        </div>
        <h3 className="font-heading font-semibold leading-tight text-foreground">{game.name}</h3>
        {game.description ? (
          <p className="line-clamp-2 text-xs leading-5 text-muted-foreground">{game.description}</p>
        ) : null}
        {game.supportedEventTypes.length > 0 ? (
          <div className="mt-1 flex flex-wrap gap-1">
            {game.supportedEventTypes.map((type) => (
              <span
                className="rounded border border-border px-1.5 py-0.5 text-xs text-muted-foreground"
                key={type}
              >
                {type}
              </span>
            ))}
          </div>
        ) : null}
      </div>
    </Link>
  );
}
