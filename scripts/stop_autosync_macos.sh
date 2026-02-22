#!/bin/bash

set -euo pipefail

LABEL="com.jaime.uwc.website.autosync"
PLIST_PATH="${HOME}/Library/LaunchAgents/${LABEL}.plist"
PID_FILE="${HOME}/.uwc-website-autosync.pid"
SCREEN_SESSION="uwc_website_autosync"

if [ -f "${PLIST_PATH}" ]; then
  launchctl unload "${PLIST_PATH}" >/dev/null 2>&1 || true
  rm -f "${PLIST_PATH}"
  echo "Auto-sync LaunchAgent stopped and removed."
else
  echo "No auto-sync LaunchAgent plist found at ${PLIST_PATH}"
fi

if command -v screen >/dev/null 2>&1; then
  if screen -ls 2>/dev/null | grep -q "[.]${SCREEN_SESSION}[[:space:]]"; then
    screen -S "${SCREEN_SESSION}" -X quit || true
    echo "Auto-sync screen session stopped (${SCREEN_SESSION})."
  fi
fi

if [ -f "${PID_FILE}" ]; then
  PID="$(cat "${PID_FILE}" 2>/dev/null || true)"
  if [ -n "${PID}" ] && kill -0 "${PID}" >/dev/null 2>&1; then
    kill "${PID}" >/dev/null 2>&1 || true
    echo "Auto-sync session process stopped (PID ${PID})."
  fi
  rm -f "${PID_FILE}"
fi
