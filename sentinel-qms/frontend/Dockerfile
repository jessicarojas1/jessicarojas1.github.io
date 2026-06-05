# syntax=docker/dockerfile:1

# ---- Build stage ----
FROM node:20-alpine AS build
WORKDIR /app

# Install dependencies based on lockfile when present for reproducible builds.
COPY package.json package-lock.json* ./
RUN npm ci || npm install

COPY . .
ARG VITE_API_BASE_URL=/api/v1
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL
RUN npm run build

# ---- Runtime stage ----
FROM nginx:alpine AS runtime

# Run as an unprivileged user (DoD STIG / least privilege).
RUN addgroup -S sentinel && adduser -S sentinel -G sentinel

COPY nginx.conf /etc/nginx/nginx.conf
COPY --from=build /app/dist /usr/share/nginx/html

# Make the directories nginx needs writable by the non-root user.
RUN chown -R sentinel:sentinel /usr/share/nginx/html \
    && touch /var/run/nginx.pid \
    && chown -R sentinel:sentinel /var/run/nginx.pid \
    && chown -R sentinel:sentinel /var/cache/nginx /var/log/nginx

USER sentinel
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
  CMD wget -q --spider http://127.0.0.1:8080/healthz || exit 1

CMD ["nginx", "-g", "daemon off;"]
