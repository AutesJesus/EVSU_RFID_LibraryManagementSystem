#!/usr/bin/env bash
# Upload from your PC when GitHub Actions FTP fails.
# Requires: lftp (Git Bash: pacman -S lftp) or use FileZilla manually.
set -euo pipefail

FTP_SERVER="${FTP_SERVER:-145.79.14.96}"
FTP_USERNAME="${FTP_USERNAME:-u710415762}"
FTP_PORT="${FTP_PORT:-21}"
FTP_REMOTE_DIR="${FTP_REMOTE_DIR:-domains/coral-seahorse-316772.hostingersite.com/public_html}"

if [ -z "${FTP_PASSWORD:-}" ]; then
  echo "Set FTP_PASSWORD first, e.g.: export FTP_PASSWORD='your-ftp-password'"
  exit 1
fi

composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null || true

if [ -n "${DB_HOST:-}" ]; then
  DB_AUTO_CREATE=false php scripts/ci-write-config.php
fi

echo "Uploading to ftps://${FTP_SERVER}:${FTP_PORT}/${FTP_REMOTE_DIR} ..."
lftp -u "${FTP_USERNAME}","${FTP_PASSWORD}" -e "\
  set ssl:verify-certificate no; \
  set ftp:ssl-allow true; \
  open -p ${FTP_PORT} ftps://${FTP_SERVER}; \
  cd \"${FTP_REMOTE_DIR}\"; \
  mirror -R --exclude .git/ --exclude .github/ --exclude .ssh/ --exclude uploads/ ./ ./; \
  bye"

echo "Done. Open: http://coral-seahorse-316772.hostingersite.com/"
