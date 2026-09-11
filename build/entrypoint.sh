#!/bin/sh
set -eu

mkdir -p /data/sessions
chown -R www-data:www-data /data 2>/dev/null || true
chmod 700 /data || true
chmod 700 /data/sessions || true

# Persist the files the upstream app expects in its document root.
for name in .admin-hash .api-token; do
  target="/data/${name}"
  link="/var/www/html/${name}"
  rm -f "${link}"
  ln -s "${target}" "${link}"
  # Linux symlink hardening can reject a root-owned symlink when Apache
  # follows it as www-data, even when the target itself is readable.
  # Own the symlink (not the target) by the web-server user.
  chown -h www-data:www-data "${link}" 2>/dev/null || true
done

exec docker-php-entrypoint "$@"
