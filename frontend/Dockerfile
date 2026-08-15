FROM node:26-alpine AS base

# Node >= 25 no longer ships corepack; reinstall it so the packageManager pin in package.json keeps
# working. Kept in its own stage rather than in `base` so the runner does not inherit the build
# toolchain - see the npm strip in the runner stage.
FROM base AS toolchain
RUN npm install -g corepack && corepack enable pnpm

FROM toolchain AS deps
WORKDIR /app
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile

FROM toolchain AS builder
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .
ENV NEXT_TELEMETRY_DISABLED=1
ARG NEXT_PUBLIC_TWITCH_CHANNEL_LOGIN=archilan
ARG NEXT_PUBLIC_API_BASE_URL=https://api.archilan.fr
ARG NEXT_PUBLIC_MERCURE_URL=https://mercure.archilan.fr/.well-known/mercure
ARG NEXT_PUBLIC_APP_URL=https://archilan.fr
ENV NEXT_PUBLIC_TWITCH_CHANNEL_LOGIN=$NEXT_PUBLIC_TWITCH_CHANNEL_LOGIN \
    NEXT_PUBLIC_API_BASE_URL=$NEXT_PUBLIC_API_BASE_URL \
    NEXT_PUBLIC_MERCURE_URL=$NEXT_PUBLIC_MERCURE_URL \
    NEXT_PUBLIC_APP_URL=$NEXT_PUBLIC_APP_URL
RUN pnpm build
# sharp's native binary dlopens libvips at runtime - a dependency Next's file tracing cannot see,
# so the standalone output ships sharp without the libvips package and the image optimizer
# silently serves originals (34.7 finding). Stage the @img native packages here (shell glob keeps
# this version-agnostic) so the runner can graft them into the traced .pnpm store.
RUN mkdir -p /sharp-native && cp -r node_modules/.pnpm/@img+* /sharp-native/

FROM base AS runner
WORKDIR /app
# npm vendors its whole dependency tree under /usr/local/lib/node_modules/npm/node_modules, and those
# copies lag upstream: npm 11.19 (shipped by node:26-alpine) and npm 12.0 both vendor brace-expansion
# 5.0.7 and ip-address 10.2.0, which Trivy flags HIGH (CVE-2026-14257, CVE-2026-69152, CVE-2026-69192).
# Upgrading npm does not help, and pnpm overrides cannot reach a tree that is not ours. The runtime
# never invokes npm - the entrypoint is `node server.js` - so drop it rather than ship the CVEs.
RUN rm -rf /usr/local/lib/node_modules/npm /usr/local/bin/npm /usr/local/bin/npx
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME="0.0.0.0"
RUN addgroup -S nodejs && adduser -S nextjs -G nodejs
COPY --from=builder /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static
COPY --from=builder --chown=nextjs:nodejs /sharp-native ./node_modules/.pnpm/
USER nextjs
EXPOSE 3000
CMD ["node", "server.js"]
