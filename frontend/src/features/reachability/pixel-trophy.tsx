// Pixel art ArchiLAN logo — 6 sphères 6×6 px en anneau hexagonal, grille 15×16, P=6px.
// Ordre Z : M(NW) → E(NE) → R(N) → E2(SW) → N(SE) → Y(S) — les derniers passent devant.
// Ligne basse de M et E retirée : E2 et N passent devant à la jointure (effet profondeur).
export function PixelTrophy({ size = 6 }: { size?: number }) {
    const C = {
        R: "#ef4444", // rouge   — haut (N)
        E: "#06b6d4", // cyan    — haut-droite (NE) + bas-gauche (SW)
        N: "#334155", // navy    — bas-droite (SE)
        Y: "#f59e0b", // ambre   — bas (S, la plus proéminente)
        M: "#e879f9", // magenta — haut-gauche (NW)
    } as const;
    type K = keyof typeof C;

    const px: [number, number, K][] = [
        // Y — bas (S), top-left (5,10), cercle complet — passe devant E2 et N aux jointures
        [6, 10, "Y"], [7, 10, "Y"], [8, 10, "Y"], [9, 10, "Y"],
        [5, 11, "Y"], [6, 11, "Y"], [7, 11, "Y"], [8, 11, "Y"], [9, 11, "Y"], [10, 11, "Y"],
        [5, 12, "Y"], [6, 12, "Y"], [7, 12, "Y"], [8, 12, "Y"], [9, 12, "Y"], [10, 12, "Y"],
        [5, 13, "Y"], [6, 13, "Y"], [7, 13, "Y"], [8, 13, "Y"], [9, 13, "Y"], [10, 13, "Y"],
        [5, 14, "Y"], [6, 14, "Y"], [7, 14, "Y"], [8, 14, "Y"], [9, 14, "Y"], [10, 14, "Y"],
        [6, 15, "Y"], [7, 15, "Y"], [8, 15, "Y"], [9, 15, "Y"],
        // E2 — bas-gauche (SW), top-left (1,8), cercle complet — ligne haute visible (M retiré)
        [2, 8, "E"], [3, 8, "E"], [4, 8, "E"], [5, 8, "E"],
        [1, 9, "E"], [2, 9, "E"], [3, 9, "E"], [4, 9, "E"], [5, 9, "E"], [6, 9, "E"],
        [1, 10, "E"], [2, 10, "E"], [3, 10, "E"], [4, 10, "E"], [5, 10, "E"], [6, 10, "E"],
        [1, 11, "E"], [2, 11, "E"], [3, 11, "E"], [4, 11, "E"], [5, 11, "E"], [6, 11, "E"],
        [1, 12, "E"], [2, 12, "E"], [3, 12, "E"], [4, 12, "E"], [5, 12, "E"], [6, 12, "E"],
        [2, 13, "E"], [3, 13, "E"], [4, 13, "E"], [5, 13, "E"],
        // N — bas-droite (SE), top-left (9,8), cercle complet — ligne haute visible (E retiré)
        [10, 8, "N"], [11, 8, "N"], [12, 8, "N"], [13, 8, "N"],
        [9, 9, "N"], [10, 9, "N"], [11, 9, "N"], [12, 9, "N"], [13, 9, "N"], [14, 9, "N"],
        [9, 10, "N"], [10, 10, "N"], [11, 10, "N"], [12, 10, "N"], [13, 10, "N"], [14, 10, "N"],
        [9, 11, "N"], [10, 11, "N"], [11, 11, "N"], [12, 11, "N"], [13, 11, "N"], [14, 11, "N"],
        [9, 12, "N"], [10, 12, "N"], [11, 12, "N"], [12, 12, "N"], [13, 12, "N"], [14, 12, "N"],
        [10, 13, "N"], [11, 13, "N"], [12, 13, "N"], [13, 13, "N"],
        // M — haut-gauche (NW), top-left (1,3), sans ligne basse (row 8 retirée → E2 passe devant)
        [2, 4, "M"], [3, 4, "M"], [4, 4, "M"], [5, 4, "M"],
        [1, 5, "M"], [2, 5, "M"], [3, 5, "M"], [4, 5, "M"], [5, 5, "M"], [6, 5, "M"],
        [1, 6, "M"], [2, 6, "M"], [3, 6, "M"], [4, 6, "M"], [5, 6, "M"], [6, 6, "M"],
        [1, 7, "M"], [2, 7, "M"], [3, 7, "M"], [4, 7, "M"], [5, 7, "M"], [6, 7, "M"],
        [1, 8, "M"], [2, 8, "M"], [3, 8, "M"], [4, 8, "M"], [5, 8, "M"], [6, 8, "M"],
        // E — haut-droite (NE), top-left (9,3), sans ligne basse (row 8 retirée → N passe devant)
        [10, 4, "E"], [11, 4, "E"], [12, 4, "E"], [13, 4, "E"],
        [9, 5, "E"], [10, 5, "E"], [11, 5, "E"], [12, 5, "E"], [13, 5, "E"], [14, 5, "E"],
        [9, 6, "E"], [10, 6, "E"], [11, 6, "E"], [12, 6, "E"], [13, 6, "E"], [14, 6, "E"],
        [9, 7, "E"], [10, 7, "E"], [11, 7, "E"], [12, 7, "E"], [13, 7, "E"], [14, 7, "E"],
        [9, 8, "E"], [10, 8, "E"], [11, 8, "E"], [12, 8, "E"], [13, 8, "E"], [14, 8, "E"],
        // R — haut (N), top-left (5,0), cercle complet — passe devant M et E aux jointures
        [6, 1, "R"], [7, 1, "R"], [8, 1, "R"], [9, 1, "R"],
        [5, 2, "R"], [6, 2, "R"], [7, 2, "R"], [8, 2, "R"], [9, 2, "R"], [10, 2, "R"],
        [5, 3, "R"], [6, 3, "R"], [7, 3, "R"], [8, 3, "R"], [9, 3, "R"], [10, 3, "R"],
        [5, 4, "R"], [6, 4, "R"], [7, 4, "R"], [8, 4, "R"], [9, 4, "R"], [10, 4, "R"],
        [5, 5, "R"], [6, 5, "R"], [7, 5, "R"], [8, 5, "R"], [9, 5, "R"], [10, 5, "R"],
        [6, 6, "R"], [7, 6, "R"], [8, 6, "R"], [9, 6, "R"],
    ];

    const P = size;
    const shadow = px.map(([c, r, k]) => `${c * P}px ${r * P}px 0 0 ${C[k]}`).join(", ");

    return (
        <div style={{ width: 15 * P, height: 16 * P, position: "relative" }}>
            <div
                aria-hidden="true"
                style={{ position: "absolute", width: P, height: P, backgroundColor: "transparent", boxShadow: shadow }}
            />
        </div>
    );
}
