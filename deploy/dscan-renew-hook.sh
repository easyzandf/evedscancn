#!/bin/bash
# certbot deploy hook: sync renewed cert into the OpenResty container path and reload.
# Runs only after a successful renewal of dscan.dpdns.org.
set -e

LIVE=/etc/letsencrypt/live/dscan.dpdns.org
DEST=/opt/1panel/apps/openresty/openresty/www/ssl

cp "$LIVE/fullchain.pem" "$DEST/dscan.dpdns.org.pem"
cp "$LIVE/privkey.pem"   "$DEST/dscan.dpdns.org.key"
chmod 644 "$DEST/dscan.dpdns.org.pem"
chmod 600 "$DEST/dscan.dpdns.org.key"

docker exec 1Panel-openresty-AW0v openresty -s reload
echo "dscan.dpdns.org certificate synced and nginx reloaded"
