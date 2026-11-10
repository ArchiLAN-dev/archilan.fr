import { AdminEventNav } from "@/features/admin/event-hub/admin-event-nav";

type Props = { children: React.ReactNode; params: Promise<{ eventId: string }> };

/** Story 3.17: every page of an event shares the hub's sections. */
export default async function AdminEventLayout({ children, params }: Props) {
  const { eventId } = await params;

  return (
    <div className="grid gap-4 px-4 pt-6 lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-6">
      <AdminEventNav eventId={eventId} />
      <div className="min-w-0">{children}</div>
    </div>
  );
}
