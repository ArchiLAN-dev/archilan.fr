"use client";

import { useCallback, useEffect } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

import { SHEET_TAB_PARAM, resolveSheetTab, type SheetTabId } from "./admin-sheet-section";

/**
 * The open tab of the sheet (story 36.8), kept in the address so a reload or a shared link opens the same
 * one. An old link to a panel (`#pelles`) is turned into its tab on arrival, then scrolled to once shown.
 */
export function useSheetTab(ready: boolean): [SheetTabId, (tab: SheetTabId) => void] {
  const searchParams = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const param = searchParams.get(SHEET_TAB_PARAM);
  const active = resolveSheetTab(param, "");

  const select = useCallback(
    (tab: SheetTabId) => router.replace(`${pathname}?${SHEET_TAB_PARAM}=${tab}`, { scroll: false }),
    [router, pathname],
  );

  // The hash only exists in the browser: read after mount, so the server render and the first client render agree.
  useEffect(() => {
    const hash = window.location.hash;
    if (param !== null || hash === "") return;
    router.replace(`${pathname}?${SHEET_TAB_PARAM}=${resolveSheetTab(null, hash)}${hash}`, { scroll: false });
  }, [param, pathname, router]);

  useEffect(() => {
    const anchor = window.location.hash.slice(1);
    if (ready && anchor !== "") document.getElementById(anchor)?.scrollIntoView({ block: "start" });
  }, [active, ready]);

  return [active, select];
}
