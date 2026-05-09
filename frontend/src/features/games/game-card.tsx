import { Gamepad2 } from "lucide-react";
import type { PublicGame } from "./public-games-api";

const availabilityConfig = {
  available: { label: "Disponible", className: "border-success/50 bg-success/10 text-success" },
  experimental: { label: "Expérimental", className: "border-warning/50 bg-warning/10 text-warning" },
} as const;

export function GameCard({ game }: { game: PublicGame }) {
  const status = availabilityConfig[game.availability] ?? availabilityConfig.available;

  return (
    <article className="card-glow grid grid-rows-[auto_1fr] overflow-hidden rounded-lg border border-border transition-colors hover:border-accent">
      <div className="relative aspect-[3/4] overflow-hidden bg-surface">
        {game.coverImageUrl ? (
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
        <div className="absolute inset-0 bg-gradient-to-t from-background/70 via-transparent to-transparent" />
        <span
          className={`absolute right-2 top-2 rounded border px-2 py-0.5 text-xs font-semibold backdrop-blur-sm ${status.className}`}
        >
          {status.label}
        </span>
      </div>

      <div className="grid content-start gap-2 p-4">
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
    </article>
  );
}
