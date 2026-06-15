"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { env } from "@/lib/env";
import {
  apiFetch,
  coordinatedRefresh,
  refreshIfStale,
  registerUnauthenticatedHandler,
} from "@/lib/apiFetch";

// Refresh the access token (15 min TTL) 2 minutes before expiry.
// Keeps long-running pages like the tracker alive without user interaction.
const PROACTIVE_REFRESH_MS = 13 * 60 * 1000; // 13 min
// When a passive tab becomes active again, refresh if the last one is older than this.
// Browsers throttle/freeze timers on inactive/background tabs and across sleep, so the
// interval alone can miss the 15 min window - visibility/focus catches that.
const REFRESH_ON_RESUME_MS = 2 * 60 * 1000; // 2 min

export type AuthUser = {
  id: string;
  email: string;
  displayName: string | null;
  roles: string[];
  emailVerifiedAt: string | null;
  steamProfile: string | null;
};

type AuthContextValue = {
  user: AuthUser | null;
  loading: boolean;
  setUser: (user: AuthUser | null) => void;
};

const AuthContext = createContext<AuthContextValue>({
  user: null,
  loading: true,
  setUser: () => {},
});

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUserState] = useState<AuthUser | null>(null);
  const [loading, setLoading] = useState(true);
  const wasEverAuthenticated = useRef(false);
  const router = useRouter();

  const setUser = useCallback((u: AuthUser | null) => {
    if (u !== null) wasEverAuthenticated.current = true;
    setUserState(u);
  }, []);

  useEffect(() => {
    registerUnauthenticatedHandler((nextPath: string) => {
      setUserState(null);
      if (wasEverAuthenticated.current) {
        router.push(`/connexion?returnTo=${encodeURIComponent(nextPath)}`);
      }
    });
  }, [router]);

  useEffect(() => {
    apiFetch(`${env.apiBaseUrl}/account/profile`)
      .then((r) => (r.ok ? r.json() : null))
      .then((payload: { data: AuthUser } | null) => {
        if (payload?.data) setUser(payload.data);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [setUser]);

  // Proactive silent refresh: keeps the session alive on passive pages.
  // Goes through `coordinatedRefresh` (Web Lock + recent-ts skip), NOT a direct
  // `POST /auth/refresh`: with several tabs open their intervals fire near-
  // simultaneously, and uncoordinated rotations would race on the same refresh
  // token and trip the server's reuse detection (logging everyone out).
  useEffect(() => {
    if (!user) return;

    // Backstop for long-active (foreground) tabs.
    const id = setInterval(() => {
      void coordinatedRefresh();
    }, PROACTIVE_REFRESH_MS);

    // Catch the throttled/frozen-timer case: when the tab becomes active again, refresh
    // if it's been a while (the interval may not have fired while backgrounded/asleep).
    const onResume = () => {
      if (document.visibilityState === "visible") {
        void refreshIfStale(REFRESH_ON_RESUME_MS);
      }
    };
    document.addEventListener("visibilitychange", onResume);
    window.addEventListener("focus", onResume);

    return () => {
      clearInterval(id);
      document.removeEventListener("visibilitychange", onResume);
      window.removeEventListener("focus", onResume);
    };
  }, [user]);

  return (
    <AuthContext.Provider value={{ user, loading, setUser }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}
