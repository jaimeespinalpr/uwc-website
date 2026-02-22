#!/bin/bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
PID_FILE="${HOME}/.uwc-website-autosync.pid"
LOG_FILE="${HOME}/Library/Logs/uwc-website-autosync-session.log"
SCREEN_SESSION="uwc_website_autosync"

mkdir -p "${HOME}/Library/Logs"

if command -v screen >/dev/null 2>&1; then
  if screen -ls 2>/dev/null | grep -q "[.]${SCREEN_SESSION}[[:space:]]"; then
    echo "Auto-sync screen session is already running (${SCREEN_SESSION})."
    echo "Log: ${LOG_FILE}"
    exit 0
  fi

  screen -L -Logfile "${LOG_FILE}" -dmS "${SCREEN_SESSION}" /bin/bash "${REPO_ROOT}/scripts/auto_sync_loop.sh" "${REPO_ROOT}"
  echo "Auto-sync started in detached screen session: ${SCREEN_SESSION}"
  echo "Log: ${LOG_FILE}"
  echo "To view live output: screen -r ${SCREEN_SESSION}"
  echo "To detach again: Ctrl+A then D"
  exit 0
fi

if [ -f "${PID_FILE}" ]; then
  EXISTING_PID="$(cat "${PID_FILE}" 2>/dev/null || true)"
  if [ -n "${EXISTING_PID}" ] && kill -0 "${EXISTING_PID}" >/dev/null 2>&1; then
    echo "Auto-sync session is already running (PID ${EXISTING_PID})."
    echo "Log: ${LOG_FILE}"
    exit 0
  fi
  rm -f "${PID_FILE}"
fi

nohup /bin/bash "${REPO_ROOT}/scripts/auto_sync_loop.sh" "${REPO_ROOT}" >> "${LOG_FILE}" 2>&1 &
NEW_PID=$!
echo "${NEW_PID}" > "${PID_FILE}"

echo "Auto-sync session started (PID ${NEW_PID})."
echo "Log: ${LOG_FILE}"
echo "PID file: ${PID_FILE}"
