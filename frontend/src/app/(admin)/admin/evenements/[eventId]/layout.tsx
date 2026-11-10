import { AdminEventNav } from "@/features/admin/event-hub/admin-event-nav";

type Props = { children: React.ReactNode; params: Promise<{ eventId: string }> };

/** Story 3.17: every page of an event shares the hub's sections; story 3.18 puts them in a bar across the top. */
export default async function AdminEventLayout({ children, params }: Props) {
  const { eventId } = await params;

  return (
    // The section pages keep their own padding; the bar and the hub overview get theirs, so it is not doubled.
    <div className="min-w-0">
      <AdminEventNav eventId={eventId} />
      <div className="min-w-0">{children}</div>
    </div>
  );
}
