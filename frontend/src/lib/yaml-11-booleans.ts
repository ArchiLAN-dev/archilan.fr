/**
 * Protéger les scalaires que YAML 1.1 lirait comme des booléens.
 *
 * Nous écrivons le YAML avec js-yaml, qui suit **YAML 1.2** : `on`, `off`, `yes` et `no` y sont des
 * chaînes ordinaires, donc émises sans guillemets. Archipelago le relit avec PyYAML, resté en
 * **YAML 1.1**, où ces mêmes mots sont des booléens.
 *
 * Un `battle_scene: on` sorti d'ici arrive donc chez l'apworld en `True`, et Pokemon Platinum
 * répond `ValueError: invalid battle scene: "False"`. Le fichier est valide des deux côtés : ce sont
 * les deux versions de la norme qui ne sont pas d'accord, et c'est nous qui écrivons.
 *
 * `true` et `false` ne sont pas concernés : js-yaml les cite déjà, puisqu'ils sont ambigus dans sa
 * propre version.
 */

/** Les scalaires que YAML 1.1 résout en booléen et que YAML 1.2 laisse en chaîne. */
const YAML_11_BOOLEANS = new Set([
  "y", "Y", "yes", "Yes", "YES",
  "n", "N", "no", "No", "NO",
  "on", "On", "ON",
  "off", "Off", "OFF",
]);

function isAmbiguous(token: string): boolean {
  return YAML_11_BOOLEANS.has(token);
}

/**
 * Cite ces scalaires dans un document déjà sérialisé, valeurs, clés et éléments de liste.
 *
 * Une passe sur le texte plutôt qu'une option de js-yaml : `forceQuotes` citerait **toutes** les
 * chaînes, y compris le nom du joueur et celui du jeu, et le YAML que le joueur télécharge n'a pas
 * à devenir illisible pour trois mots. js-yaml n'offre pas de style par valeur.
 */
export function quoteYaml11Booleans(yamlText: string): string {
  return yamlText
    .split("\n")
    .map((line) => {
      // Élément de liste : "  - on"
      const item = /^(\s*-\s+)([^\s#'"][^\n]*)$/.exec(line);
      if (item && isAmbiguous(item[2])) return `${item[1]}'${item[2]}'`;

      // Paire clé/valeur : "  battle_scene: on" - la clé et la valeur sont vulnérables toutes deux,
      // une option de choix pouvant s'appeler "on" comme une valeur peut valoir "on".
      const pair = /^(\s*)([^\s#'"][^:]*?):(?:[ \t]+([^\n]*))?$/.exec(line);
      if (pair) {
        const [, indent, rawKey, rawValue] = pair;
        const key = isAmbiguous(rawKey) ? `'${rawKey}'` : rawKey;
        if (rawValue === undefined) return `${indent}${key}:`;
        const value = isAmbiguous(rawValue) ? `'${rawValue}'` : rawValue;

        return `${indent}${key}: ${value}`;
      }

      return line;
    })
    .join("\n");
}
