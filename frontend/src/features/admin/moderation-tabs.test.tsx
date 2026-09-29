import { renderToStaticMarkup } from "react-dom/server";

import { ModerationTabs } from "./moderation-tabs";

function render(tab: "reports" | "contributions", counts = { reports: 12, contributions: 0 }): string {
  return renderToStaticMarkup(<ModerationTabs counts={counts} onChange={() => undefined} tab={tab} />);
}

/**
 * Story 39.13. The moderation tabs are a real tab bar: a framed control, an icon, the label and the number
 * waiting in each, the current one raised.
 */
describe("ModerationTabs", () => {
  test("a tablist with one tab per queue, the current one selected", () => {
    const html = render("reports");

    expect(html).toContain('role="tablist"');
    expect(html).toContain('aria-label="Files de modération"');
    expect(html).toMatch(/role="tab"[^>]*aria-selected="true"|aria-selected="true"[^>]*role="tab"/);
    expect(html).toMatch(/aria-selected="true"[^>]*>.*Signalements/);
    expect(html).toMatch(/aria-selected="false"[^>]*>.*Contributions tutoriels/);
  });

  test("each tab carries its icon and its count, none when nothing waits", () => {
    const html = render("reports");

    expect(html).toContain("lucide-flag");
    expect(html).toContain("lucide-book-open");
    expect(html).toMatch(/Signalements<\/span><span[^>]*>12<\/span>/);
    expect(html).not.toMatch(/Contributions tutoriels<\/span><span/);
  });

  test("the current tab is raised on the frame", () => {
    const html = render("contributions", { reports: 12, contributions: 3 });

    expect(html).toMatch(/aria-selected="true"[^>]*class="[^"]*bg-background[^"]*shadow/);
    expect(html).toMatch(/aria-selected="false"[^>]*class="[^"]*text-muted-foreground/);
  });
});
