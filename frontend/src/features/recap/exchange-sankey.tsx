"use client";

import { ResponsiveContainer, Sankey } from "recharts";

export type ExchangeSlot = {
  slotId: string;
  /** The AP slot name - unique within a run, unlike the player name when one person holds several slots. */
  slotName: string;
  game: string;
  color: string;
};

export type ExchangeFlow = { fromSlotId: string; toSlotId: string; count: number };
export type ExchangeLocal = { slotId: string; count: number };

type Props = {
  slots: ExchangeSlot[];
  flows: ExchangeFlow[];
  locals: ExchangeLocal[];
};

type FlowNode = { name: string; side: "out" | "in"; color: string; total: number; game: string };

const NODE_ROW_HEIGHT = 84;
const MIN_HEIGHT = 240;

/** Reads a field off recharts' loosely-typed render payload without an `as` cast (AC-TS2/AC-TS3). */
function readString(source: unknown, key: string): string | null {
  if (typeof source !== "object" || source === null || !(key in source)) return null;
  const value: unknown = Reflect.get(source, key);
  return typeof value === "string" ? value : null;
}

function readNumber(source: unknown, key: string): number | null {
  if (typeof source !== "object" || source === null || !(key in source)) return null;
  const value: unknown = Reflect.get(source, key);
  return typeof value === "number" && Number.isFinite(value) ? value : null;
}

function readObject(source: unknown, key: string): unknown {
  if (typeof source !== "object" || source === null || !(key in source)) return null;
  return Reflect.get(source, key);
}

type NodeRenderProps = { x: number; y: number; width: number; height: number; payload: unknown };

/**
 * A node is the coloured spine of one side of one slot, with its name and its total just outside
 * the plot - senders label to the left, receivers to the right, so the two columns read as columns.
 */
function renderNode({ x, y, width, height, payload }: NodeRenderProps) {
  const name = readString(payload, "name") ?? "";
  const side = readString(payload, "side");
  const color = readString(payload, "color") ?? "var(--chart-series-1)";
  const total = readNumber(payload, "total") ?? 0;
  const isSender = side === "out";
  const textX = isSender ? x - 10 : x + width + 10;
  const anchor = isSender ? "end" : "start";
  const middle = y + height / 2;

  return (
    <g>
      <rect fill={color} height={height} rx={3} width={width} x={x} y={y} />
      <text
        fill="var(--color-foreground)"
        fontSize={12}
        fontWeight={600}
        textAnchor={anchor}
        x={textX}
        y={middle - 2}
      >
        {name}
      </text>
      <text fill="var(--color-muted-foreground)" fontSize={11} textAnchor={anchor} x={textX} y={middle + 13}>
        {total} {isSender ? "envoyés aux autres" : "reçus des autres"}
      </text>
    </g>
  );
}

/**
 * Point at parameter `t` on the cubic the ribbon follows - the same curve recharts draws, so a
 * label placed with this always lands on its own ribbon rather than beside it.
 */
function pointOnCurve(
  t: number,
  c: { sourceX: number; sourceY: number; sourceControlX: number; targetControlX: number; targetX: number; targetY: number },
): { x: number; y: number } {
  const u = 1 - t;
  const b0 = u * u * u;
  const b1 = 3 * u * u * t;
  const b2 = 3 * u * t * t;
  const b3 = t * t * t;
  return {
    x: b0 * c.sourceX + b1 * c.sourceControlX + b2 * c.targetControlX + b3 * c.targetX,
    y: (b0 + b1) * c.sourceY + (b2 + b3) * c.targetY,
  };
}

type LinkRenderProps = {
  sourceX: number;
  targetX: number;
  sourceY: number;
  targetY: number;
  sourceControlX: number;
  targetControlX: number;
  linkWidth: number;
  index: number;
  payload: unknown;
};

/**
 * One ribbon per ordered pair, drawn in the sender's colour so a flow is attributable at a glance,
 * with its item count written at the midpoint - the count is the point of the section, so it is
 * never hidden behind a hover.
 */
function renderLink({
  sourceX,
  targetX,
  sourceY,
  targetY,
  sourceControlX,
  targetControlX,
  linkWidth,
  index,
  payload,
}: LinkRenderProps) {
  const source = readObject(payload, "source");
  const color = readString(source, "color") ?? "var(--chart-series-1)";
  const value = readNumber(payload, "value") ?? 0;
  const sourceName = readString(source, "name") ?? "";
  const targetName = readString(readObject(payload, "target"), "name") ?? "";
  const isLocal = sourceName === targetName;
  // Two ribbons running in opposite directions cross at the middle, so a midpoint label would sit
  // on top of the other one - exactly the collision the old graph suffered from. Anchoring the
  // count near its own source separates the labels by construction, since each ribbon leaves its
  // sender at a different height.
  const labelAt = pointOnCurve(0.18, { sourceControlX, sourceX, sourceY, targetControlX, targetX, targetY });

  return (
    <g key={`link-${index}`}>
      <path
        d={`M${sourceX},${sourceY}C${sourceControlX},${sourceY} ${targetControlX},${targetY} ${targetX},${targetY}`}
        fill="none"
        opacity={isLocal ? 0.28 : 0.5}
        stroke={color}
        strokeWidth={Math.max(1, linkWidth)}
      >
        <title>
          {isLocal
            ? `${sourceName} a gardé ${value} objet(s) pour lui-même`
            : `${sourceName} a envoyé ${value} objet(s) à ${targetName}`}
        </title>
      </path>
      <text
        fill="var(--color-foreground)"
        fontSize={11}
        fontWeight={700}
        paintOrder="stroke"
        stroke="var(--color-surface)"
        strokeWidth={3}
        textAnchor="middle"
        x={labelAt.x}
        y={labelAt.y + 4}
      >
        {value}
      </text>
    </g>
  );
}

/**
 * The item-exchange diagram of a finished multiworld (story 9.48).
 *
 * Replaces the force-directed canvas graph, which was the wrong tool for this data: a force layout
 * reveals structure in a dense network, while an ArchiLAN run has 2 to 6 slots. At that size it
 * settled into two circles with both directions of exchange drawn as superimposed straight lines,
 * so the one thing the section promises - who sent what to whom - was unreadable, and the per-pair
 * counts were never displayed at all.
 *
 * Each slot appears twice: as a sender on the left, as a receiver on the right. That split is what
 * makes the picture possible - the exchange graph is cyclic (A sends to B *and* B sends to A) while
 * a Sankey layout requires an acyclic graph - and it happens to read exactly like the question the
 * section asks. Items a slot found for itself, invisible in the old graph, are the ribbon that
 * crosses straight over. Built on the recharts Sankey already in the bundle: no new dependency.
 */
export function ExchangeSankey({ slots, flows, locals }: Props) {
  // Index layout: slot i occupies node 2i (sender side) and node 2i + 1 (receiver side).
  const indexBySlotId = new Map(slots.map((slot, i) => [slot.slotId, i]));

  const sentBySlot = new Map<string, number>();
  const receivedBySlot = new Map<string, number>();
  for (const flow of flows) {
    sentBySlot.set(flow.fromSlotId, (sentBySlot.get(flow.fromSlotId) ?? 0) + flow.count);
    receivedBySlot.set(flow.toSlotId, (receivedBySlot.get(flow.toSlotId) ?? 0) + flow.count);
  }
  // Totals count exchanges *with other players* and deliberately exclude a slot's own finds, which
  // stay readable on their own straight-through ribbon. This is the same notion the "most generous"
  // superlative measures - counting local finds here would print a different number for the same
  // idea a few hundred pixels apart on the page.

  const nodes: FlowNode[] = slots.flatMap((slot) => [
    {
      color: slot.color,
      game: slot.game,
      name: slot.slotName,
      side: "out" as const,
      total: sentBySlot.get(slot.slotId) ?? 0,
    },
    {
      color: slot.color,
      game: slot.game,
      name: slot.slotName,
      side: "in" as const,
      total: receivedBySlot.get(slot.slotId) ?? 0,
    },
  ]);

  const links = [
    ...flows.flatMap((flow) => {
      const from = indexBySlotId.get(flow.fromSlotId);
      const to = indexBySlotId.get(flow.toSlotId);
      return from === undefined || to === undefined || flow.count === 0
        ? []
        : [{ source: from * 2, target: to * 2 + 1, value: flow.count }];
    }),
    ...locals.flatMap((local) => {
      const at = indexBySlotId.get(local.slotId);
      return at === undefined || local.count === 0 ? [] : [{ source: at * 2, target: at * 2 + 1, value: local.count }];
    }),
  ];

  if (slots.length === 0 || links.length === 0) {
    return null;
  }

  return (
    <div className="rounded-lg border border-border bg-surface p-4">
      <p className="mb-3 text-xs text-muted-foreground">
        Expéditeurs à gauche, destinataires à droite. L&apos;épaisseur d&apos;un ruban est le nombre d&apos;objets
        envoyés ; un ruban qui traverse tout droit correspond aux objets qu&apos;un joueur a trouvés pour lui-même.
      </p>
      {/* The two label gutters need room; below ~36rem the diagram scrolls rather than crushing
          into an unreadable strip. The sr-only table stays the non-visual path either way. */}
      <div className="overflow-x-auto">
        <div className="min-w-[36rem]">
          <ResponsiveContainer height={Math.max(MIN_HEIGHT, slots.length * NODE_ROW_HEIGHT)} width="100%">
            <Sankey
              data={{ links, nodes }}
              link={renderLink}
              margin={{ bottom: 12, left: 132, right: 132, top: 12 }}
              node={renderNode}
              nodePadding={30}
              nodeWidth={10}
            />
          </ResponsiveContainer>
        </div>
      </div>

      {/* Accessible / crawlable mirror of the diagram. */}
      <table className="sr-only">
        <caption>Échanges d&apos;objets entre joueurs</caption>
        <thead>
          <tr>
            <th>De</th>
            <th>Vers</th>
            <th>Objets envoyés</th>
          </tr>
        </thead>
        <tbody>
          {flows.map((flow) => (
            <tr key={`${flow.fromSlotId}-${flow.toSlotId}`}>
              <td>{slots.find((s) => s.slotId === flow.fromSlotId)?.slotName ?? flow.fromSlotId}</td>
              <td>{slots.find((s) => s.slotId === flow.toSlotId)?.slotName ?? flow.toSlotId}</td>
              <td>{flow.count}</td>
            </tr>
          ))}
          {locals.map((local) => (
            <tr key={`local-${local.slotId}`}>
              <td>{slots.find((s) => s.slotId === local.slotId)?.slotName ?? local.slotId}</td>
              <td>Lui-même</td>
              <td>{local.count}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
