#!/usr/bin/env bash
set -euo pipefail

# Sync built project to Hostinger over SSH (GitHub Actions or local).

SSH_HOST="${SSH_HOST:?SSH_HOST is required}"
SSH_USER="${SSH_USER:?SSH_USER is required}"
SSH_PORT="${SSH_PORT:-65002}"
SSH_REMOTE_PATH="${SSH_REMOTE_PATH:?SSH_REMOTE_PATH is required}"
SSH_IDENTITY_FILE="${SSH_IDENTITY_FILE:-$HOME/.ssh/deploy_key}"

REMOTE="${SSH_USER}@${SSH_HOST}:${SSH_REMOTE_PATH}"

RSYNC_SSH="ssh -i ${SSH_IDENTITY_FILE} -p ${SSH_PORT} -o StrictHostKeyChecking=yes"

echo "Deploying to ${REMOTE}"

# --delete removes old files on the server that were removed/renamed in the repo.
# --checksum ensures changed CSS/JS are uploaded even if timestamps differ.
rsync -avz --delete --checksum \
  -e "${RSYNC_SSH}" \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude 'config/db.local.php.example' \
  --exclude 'config/mail.local.php.example' \
  --exclude 'uploads/' \
  --exclude 'DEPLOYMENT.md' \
  --exclude 'scripts/ci-write-config.php' \
  --exclude 'deploy/' \
  --exclude '.ssh/' \
  --exclude '.ftp-deploy-sync-state.json' \
  ./ "${REMOTE}"

echo "Ensuring uploads directory exists..."
ssh -i "${SSH_IDENTITY_FILE}" -p "${SSH_PORT}" -o StrictHostKeyChecking=yes \
  "${SSH_USER}@${SSH_HOST}" \
  "mkdir -p ${SSH_REMOTE_PATH}/uploads && chmod 775 ${SSH_REMOTE_PATH}/uploads" || true

echo "Deploy finished."
