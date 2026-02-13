#!/bin/sh
# Nginx startup script
# Processes nginx.conf.template with envsubst to inject environment-specific CORS origin
# then starts nginx

# Set CORS origin from UI external URL, with a sensible default
export NGINX_CORS_ORIGIN="${MESSAGEHUB_ENDPOINTS_UI_EXTERNAL:-https://platform-dev.cofense-dev.com}"

echo "[nginx-startup] Setting CORS origin to: $NGINX_CORS_ORIGIN"

# Run envsubst to generate the actual nginx config
# IMPORTANT: Only substitute NGINX_CORS_ORIGIN to preserve nginx's own $variables
envsubst '${NGINX_CORS_ORIGIN}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf

echo "[nginx-startup] Generated nginx config from template"

# Start nginx in foreground mode (for supervisord)
exec nginx -g "daemon off;"
