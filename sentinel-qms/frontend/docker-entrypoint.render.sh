#!/bin/sh
# Render/PaaS entrypoint for the frontend nginx image.
# Renders the nginx config from a template, substituting ONLY ${PORT} and
# ${API_ORIGIN} (so nginx's own $variables such as $host are preserved), then
# starts nginx in the foreground.
set -e

export PORT="${PORT:-8080}"
export API_ORIGIN="${API_ORIGIN:-}"

envsubst '${PORT} ${API_ORIGIN}' \
  < /etc/nginx/nginx.conf.template \
  > /tmp/nginx.conf

echo "[entrypoint] nginx listening on ${PORT}; API origin allowed in CSP: '${API_ORIGIN:-(none)}'"
exec nginx -c /tmp/nginx.conf -g 'daemon off;'
