/**
 * La marque « en direct » elle-même : un point rouge qui pulse et le mot Live.
 *
 * Extraite de `live-twitch-badge.tsx` (chaîne ArchiLAN) pour que la carte joueur affiche exactement
 * le même signal sans le redessiner (story 30.39). Elle ne porte ni lien ni état : chaque appelant
 * décide vers quelle chaîne elle pointe et quand elle apparaît.
 */
export function LiveMark() {
  return (
    <>
      <span aria-hidden="true" className="relative flex size-3.5 shrink-0">
        <svg className="absolute inset-0 animate-ping" fill="none" viewBox="0 0 14 14">
          <circle cx="7" cy="7" r="6" stroke="#f87171" strokeWidth="1.5" />
        </svg>
        <svg className="relative" fill="none" viewBox="0 0 14 14">
          <circle cx="7" cy="7" fill="#991b1b" r="5" />
        </svg>
      </span>
      <span className="text-xs font-semibold uppercase tracking-widest text-red-500">Live</span>
    </>
  );
}
