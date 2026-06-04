"use client";

import { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { env } from "@/lib/env";
import { apiFetch, registerUnauthenticatedHandler } from "@/lib/apiFetch";

// Refresh the access token (15 min TTL) 2 minutes before expiry.
// Keeps long-running pages like the tracker alive without user interaction.
const PROACTIVE_REFRESH_MS = 13 * 60 * 1000; // 13 min

export type AuthUser = {
  id: string;
  email: string;
  displayName: string | null;
  roles: string[];
  emailVerifiedAt: string | null;
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
  useEffect(() => {
    if (!user) return;

    const id = setInterval(() => {
      apiFetch(`${env.apiBaseUrl}/auth/refresh`, { method: "POST" }).catch(() => {});
    }, PROACTIVE_REFRESH_MS);

    return () => clearInterval(id);
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
