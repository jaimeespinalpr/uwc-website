#!/bin/bash

set -euo pipefail

LABEL="com.jaime.uwc.website.autosync"
PLIST_PATH="${HOME}/Library/LaunchAgents/${LABEL}.plist"

if [ -f "${PLIST_PATH}" ]; then
  launchctl unload "${PLIST_PATH}" >/dev/null 2>&1 || true
  rm -f "${PLIST_PATH}"
  echo "Auto-sync LaunchAgent stopped and removed."
else
  echo "No auto-sync LaunchAgent plist found at ${PLIST_PATH}"
fi
