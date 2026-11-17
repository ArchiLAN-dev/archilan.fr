import { Client, itemsHandlingFlags } from "archipelago.js";

import type { ApGameNames } from "../engine/ap-events";
import type { ApClientLike } from "./ap-link";

/**
 * archipelago.js behind the narrow interface of `ApLink` (story 54.6). The `Tracker` tag with no game: the server
 * does not count the tracker as a game client, the player's own client stays connected, and nothing is checked.
 */
export function createArchipelagoClient(): ApClientLike {
  const client = new Client({ timeout: 10_000, autoFetchDataPackage: true });
  return {
    listen(handlers) {
      client.socket
        .on("connected", (packet) => handlers.connected(packet))
        .on("receivedItems", (packet) => handlers.receivedItems(packet))
        .on("roomUpdate", (packet) => handlers.roomUpdate(packet))
        .on("locationInfo", (packet) => handlers.locationInfo(packet))
        .on("bounced", (packet) => handlers.bounced(packet))
        .on("retrieved", (packet) => handlers.retrieved(packet))
        .on("setReply", (packet) => handlers.setReply(packet))
        .on("disconnected", () => handlers.disconnected());
    },
    async login(url, slot, password) {
      await client.login(url, slot, "", { tags: ["Tracker"], password, items: itemsHandlingFlags.all, slotData: true });
    },
    send(packets) {
      client.socket.send(...packets);
    },
    disconnect() {
      client.socket.disconnect();
    },
    names() {
      const names: Record<string, ApGameNames> = {};
      for (const [game, data] of Object.entries(client.package.exportPackage().games)) {
        const items: Record<number, string> = {};
        const locations: Record<number, string> = {};
        for (const [name, id] of Object.entries(data.item_name_to_id)) items[id] = name;
        for (const [name, id] of Object.entries(data.location_name_to_id)) locations[id] = name;
        names[game] = { items, locations };
      }
      return names;
    },
    seed() {
      return client.room.seedName;
    },
  };
}
