# syntax=docker/dockerfile:1

# ─────────────────────────────────────────────────────────────────────────────
# Compliance Copilot — multi-stage Next.js 14 (App Router) production image.
#
# Stages:
#   deps    → install production + build dependencies with a clean, locked tree
#   builder → `next build` producing the standalone output (.next/standalone)
#   runner  → minimal, non-root runtime that serves the traced server
#
# Requires next.config.js -> output: 'standalone' (set in this repo).
# Runtime env (Supabase URL/keys, ANTHROPIC_API_KEY, APP_SESSION_* …) is injected
# at container start — NOTHING secret is baked into the image.
# ─────────────────────────────────────────────────────────────────────────────

# ---- Stage 1: dependencies -------------------------------------------------
FROM node:20-alpine AS deps
WORKDIR /app
# libc6-compat helps some native/optional deps run on Alpine.
RUN apk add --no-cache libc6-compat
COPY package.json package-lock.json ./
RUN npm ci

# ---- Stage 2: build --------------------------------------------------------
FROM node:20-alpine AS builder
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
COPY --from=deps /app/node_modules ./node_modules
COPY . .
# NEXT_PUBLIC_* values are inlined at build time. They are non-secret (anon key,
# public URL); provide real values at build for correct client bundles, or leave
# them to be set at runtime for server-only code paths.
RUN npm run build

# ---- Stage 3: runtime ------------------------------------------------------
FROM node:20-alpine AS runner
WORKDIR /app

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV PORT=3000
ENV HOSTNAME=0.0.0.0

# Run as an unprivileged user.
RUN addgroup --system --gid 1001 nodejs \
 && adduser  --system --uid 1001 nextjs

# Standalone output: minimal server + only the traced node_modules.
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static     ./.next/static
COPY --from=builder --chown=nextjs:nodejs /app/public           ./public

USER nextjs
EXPOSE 3000

# No dedicated /api/health route exists yet; the login status endpoint returns
# 200 JSON without auth and is a good liveness signal. Uses Node (no curl on
# alpine) so the check has no extra package footprint.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD node -e "fetch('http://127.0.0.1:'+(process.env.PORT||3000)+'/api/auth/login').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

# server.js is emitted by Next.js standalone output at the repo root of /app.
CMD ["node", "server.js"]
