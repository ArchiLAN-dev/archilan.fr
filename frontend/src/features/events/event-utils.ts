export function getCapacityPercent(
  capacity: { remaining: number; total: number } | undefined,
): number {
  if (!capacity) return 0;
  return ((capacity.total - capacity.remaining) / capacity.total) * 100;
}

export function getCapacityBarColor(capacityPercent: number): string {
  if (capacityPercent >= 100) return "bg-danger";
  if (capacityPercent >= 75) return "bg-accent-warm";
  return "bg-accent-text";
}
