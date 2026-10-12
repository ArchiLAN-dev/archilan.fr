import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { ADMIN_HOME_ANNOUNCEMENTS_KEY, AdminHomeAnnouncements, dateAndTimeOf, isoFrom } from "@/features/admin/admin-home-announcements";
import { isHomeAnnouncement, type AdminHomeAnnouncement } from "./home-announcement-api";
import { HomeAnnouncementModal, isExternalLink, openDelayMs, seenKey } from "./home-announcement-modal";

const window = { startsAt: "2026-10-19T00:01:00+02:00", endsAt: "2026-10-31T23:59:00+01:00" };
const at = (iso: string) => new Date(iso).getTime();

const announcement: AdminHomeAnnouncement = {
  id: "a1",
  title: "Inscriptions ArchiLAN 4",
  body: "Du **19** au **31** octobre.",
  imageUrl: null,
  ctaLabel: "Je m'inscris",
  ctaUrl: "/evenements/archilan-4",
  ...window,
  status: "upcoming",
};

/** Story 10.14: the announcement opened as a modal on the home page. */
describe("home announcement", () => {
  it("opens between its dates by the browser's clock, waits before, and never after", () => {
    expect(openDelayMs(window, at("2026-10-19T00:00:00+02:00"))).toBe(60_000);
    expect(openDelayMs(window, at("2026-10-19T00:01:00+02:00"))).toBe(0);
    expect(openDelayMs(window, at("2026-10-31T23:58:59+01:00"))).toBe(0);
    expect(openDelayMs(window, at("2026-10-31T23:59:00+01:00"))).toBeNull();
    expect(openDelayMs({ startsAt: "nope", endsAt: window.endsAt }, at("2026-10-20T00:00:00+02:00"))).toBeNull();
  });

  it("remembers each announcement apart, and opens only https addresses in a new tab", () => {
    expect(seenKey("a1")).not.toBe(seenKey("a2"));
    expect(isExternalLink("https://www.helloasso.com/archilan")).toBe(true);
    expect(isExternalLink("/evenements/archilan-4")).toBe(false);
  });

  it("is closed in the page the server renders, and absent without an announcement", () => {
    expect(renderToStaticMarkup(<HomeAnnouncementModal announcement={null} />)).toBe("");
    expect(renderToStaticMarkup(<HomeAnnouncementModal announcement={announcement} />)).not.toContain(announcement.title);
  });

  it("reads only a well-formed announcement from the API", () => {
    expect(isHomeAnnouncement(announcement)).toBe(true);
    expect(isHomeAnnouncement({ ...announcement, imageUrl: 3 })).toBe(false);
    expect(isHomeAnnouncement(null)).toBe(false);
  });

  it("turns the admin's date and time into the API's date and back", () => {
    const iso = isoFrom("2026-10-19", "00:01");
    expect(iso).not.toBeNull();
    expect(iso).toMatch(/\+00:00$/);
    expect(dateAndTimeOf(iso ?? "")).toEqual({ date: "2026-10-19", time: "00:01" });
    expect(isoFrom("", "00:01")).toBeNull();
  });

  it("lists the announcements with their status for the admin", () => {
    const client = new QueryClient();
    client.setQueryData(ADMIN_HOME_ANNOUNCEMENTS_KEY, [announcement, { ...announcement, id: "a0", title: "Ancienne", status: "ended" }]);
    const html = renderToStaticMarkup(
      <QueryClientProvider client={client}>
        <AdminHomeAnnouncements />
      </QueryClientProvider>,
    );

    expect(html).toContain("Inscriptions ArchiLAN 4");
    expect(html).toContain("À venir");
    expect(html).toContain("Terminée");
    expect(html).toContain("/evenements/archilan-4");
  });
});
