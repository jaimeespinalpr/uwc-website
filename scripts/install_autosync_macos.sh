#!/bin/bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"

LABEL="com.jaime.uwc.website.autosync"
PLIST_PATH="${HOME}/Library/LaunchAgents/${LABEL}.plist"
LOG_DIR="${HOME}/Library/Logs"
STDOUT_LOG="${LOG_DIR}/uwc-website-autosync.out.log"
STDERR_LOG="${LOG_DIR}/uwc-website-autosync.err.log"

mkdir -p "${HOME}/Library/LaunchAgents" "${LOG_DIR}"

cat > "${PLIST_PATH}" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>${LABEL}</string>

  <key>ProgramArguments</key>
  <array>
    <string>/bin/bash</string>
    <string>${REPO_ROOT}/scripts/auto_sync_loop.sh</string>
    <string>${REPO_ROOT}</string>
  </array>

  <key>EnvironmentVariables</key>
  <dict>
    <key>AUTO_SYNC_BRANCH</key>
    <string>main</string>
    <key>AUTO_SYNC_INTERVAL</key>
    <string>20</string>
    <key>AUTO_SYNC_DEBOUNCE</key>
    <string>45</string>
  </dict>

  <key>WorkingDirectory</key>
  <string>${REPO_ROOT}</string>

  <key>RunAtLoad</key>
  <true/>
  <key>KeepAlive</key>
  <true/>

  <key>StandardOutPath</key>
  <string>${STDOUT_LOG}</string>
  <key>StandardErrorPath</key>
  <string>${STDERR_LOG}</string>
</dict>
</plist>
PLIST

chmod 644 "${PLIST_PATH}"

launchctl unload "${PLIST_PATH}" >/dev/null 2>&1 || true
launchctl load "${PLIST_PATH}"

echo "Auto-sync LaunchAgent installed and started."
echo "Plist: ${PLIST_PATH}"
echo "Logs:"
echo "  ${STDOUT_LOG}"
echo "  ${STDERR_LOG}"
