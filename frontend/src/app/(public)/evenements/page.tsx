import { EventCard, EventsEmptyState } from "@/features/events/event-card";
import { getPublicEvents } from "@/features/events/public-events-api";

export const dynamic = "force-dynamic";

export const metadata = {
  title: "Événements",
  description: "Événements Archipelago publics organisés par ArchiLAN.",
};

export default async function EventsPage() {
  const { past, upcoming } = await getPublicEvents();

  return (
    <div className="mx-auto w-full max-w-7xl grid gap-16">
      <section>
        <p className="mb-4 text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
          Événements ArchiLAN
        </p>
        <h1 className="font-heading text-4xl font-bold leading-tight md:text-5xl">
          Rejoins une session Archipelago.
        </h1>
        <p className="mt-5 max-w-2xl text-lg leading-8 text-muted-foreground">
          Parcours les prochaines sessions ouvertes, les événements réservés aux
          membres, et les récaps publics des multiworlds passés.
        </p>
      </section>

      <section aria-labelledby="upcoming-events" className="grid gap-6">
        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
          <div>
            <h2 className="font-heading text-3xl font-semibold" id="upcoming-events">
              Prochains événements
            </h2>
            <p className="mt-2 text-muted-foreground">
              États affichés explicitement pour aider à choisir rapidement.
            </p>
          </div>
        </div>
        {upcoming.length > 0 ? (
          <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            {upcoming.map((event) => (
              <EventCard event={event} key={event.id} />
            ))}
          </div>
        ) : (
          <EventsEmptyState />
        )}
      </section>

      <section aria-labelledby="past-events" className="grid gap-6">
        <div>
          <h2 className="font-heading text-3xl font-semibold" id="past-events">
            Événements passés
          </h2>
          <p className="mt-2 text-muted-foreground">
            Récaps, chiffres clés et archives publiques quand ils sont disponibles.
          </p>
        </div>
        {past.length > 0 ? (
          <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
            {past.map((event) => (
              <EventCard event={event} key={event.id} />
            ))}
          </div>
        ) : (
          <EventsEmptyState />
        )}
      </section>
    </div>
  );
}
