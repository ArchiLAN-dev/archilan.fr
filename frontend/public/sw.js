/*
 * ArchiLAN service worker (story 40.2): shows the site's push notifications and opens the page they point
 * to. It does nothing else - no caching, no offline mode - so it never gets between a visitor and the site.
 */

self.addEventListener("install", () => {
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener("push", (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = { body: event.data ? event.data.text() : "" };
  }

  const title = typeof data.title === "string" && data.title !== "" ? data.title : "ArchiLAN";
  const tag = typeof data.tag === "string" && data.tag !== "" ? data.tag : undefined;

  event.waitUntil(
    self.registration.showNotification(title, {
      body: typeof data.body === "string" ? data.body : "",
      icon: "/images/logo.webp",
      tag,
      renotify: tag !== undefined,
      data: { url: typeof data.url === "string" && data.url !== "" ? data.url : "/" },
    }),
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const target = new URL((event.notification.data && event.notification.data.url) || "/", self.location.origin);
  // Only ever open this site: a push payload never sends the visitor elsewhere.
  const url = target.origin === self.location.origin ? target.href : self.location.origin + "/";

  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((windows) => {
      const exact = windows.find((client) => client.url === url);
      if (exact) {
        return exact.focus();
      }
      const sameSite = windows.find((client) => new URL(client.url).origin === self.location.origin);
      if (sameSite && "navigate" in sameSite) {
        return sameSite.navigate(url).then((client) => (client ? client.focus() : undefined));
      }
      return self.clients.openWindow(url);
    }),
  );
});
