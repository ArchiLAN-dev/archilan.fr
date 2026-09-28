import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import { AccountShell } from "./account-shell";

// The shell's nav reads the route; outside the app router it needs a stand-in.
jest.mock("next/navigation", () => ({
  usePathname: () => "/compte",
  useRouter: () => ({ push: () => undefined }),
}));

function render(): string {
  const client = new QueryClient();
  client.setQueryData(["account-profile"], {
    id: "u1",
    email: "un-email-assez-long-pour-deborder@example.com",
    displayName: "masterkafey",
    roles: ["ROLE_USER"],
    emailVerifiedAt: "2026-01-01T00:00:00Z",
  });
  client.setQueryData(["community-my-profile"], null);

  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <AccountShell>
        <p>section</p>
      </AccountShell>
    </QueryClientProvider>,
  );
}

/**
 * On a phone every `/compte/*` page scrolled sideways: the shell's grid had no explicit column, so its
 * implicit track grew to the min-content of the identity card (avatar, name, email, role badge), wider
 * than the screen, and the email never got the chance to truncate. `grid-cols-1` bounds the column
 * (`minmax(0, 1fr)`) to the available width.
 */
describe("AccountShell", () => {
  test("its grids bound their single mobile column to the screen width", () => {
    const html = render();

    expect(html).toMatch(/^<div class="grid grid-cols-1 gap-6">/);
    expect(html).toContain('class="grid grid-cols-1 gap-6 md:grid-cols-[13rem_1fr] md:items-start"');
  });

  test("the long email stays truncatable inside the card", () => {
    const html = render();

    expect(html).toContain("un-email-assez-long-pour-deborder@example.com");
    expect(html).toMatch(/<div class="min-w-0 flex-1">.*class="truncate text-sm/);
  });
});
