/** Story 41.22: a title worn under the name - on the profile, in the shop and in the editor's preview. */
export function ProfileTitleBadge({ label, className = "" }: { label: string; className?: string }) {
  return (
    <span className={`inline-flex items-center rounded-full border border-accent/40 bg-accent/10 px-3 py-0.5 text-xs font-semibold uppercase tracking-wide text-accent-text ${className}`}>
      {label}
    </span>
  );
}
