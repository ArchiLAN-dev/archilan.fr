// No `as` casts — assertionStyle: "never" enforced by ESLint (see eslint.config.mjs).

export function hasStringProp<K extends string>(v: object, key: K): v is Record<K, string> {
  return key in v && typeof Reflect.get(v, key) === "string";
}

export function hasNumberProp<K extends string>(v: object, key: K): v is Record<K, number> {
  return key in v && typeof Reflect.get(v, key) === "number";
}

export function hasBooleanProp<K extends string>(v: object, key: K): v is Record<K, boolean> {
  return key in v && typeof Reflect.get(v, key) === "boolean";
}
