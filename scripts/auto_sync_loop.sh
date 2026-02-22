#!/bin/bash

set -u

REPO_PATH="${1:-$(pwd)}"
BRANCH="${AUTO_SYNC_BRANCH:-main}"
INTERVAL_SECONDS="${AUTO_SYNC_INTERVAL:-20}"
DEBOUNCE_SECONDS="${AUTO_SYNC_DEBOUNCE:-45}"

timestamp() {
  date "+%Y-%m-%d %H:%M:%S"
}

log() {
  echo "[$(timestamp)] [uwc-autosync] $*"
}

is_git_busy() {
  [ -d ".git/rebase-merge" ] || [ -d ".git/rebase-apply" ] || [ -f ".git/MERGE_HEAD" ]
}

remote_branch_exists() {
  git show-ref --verify --quiet "refs/remotes/origin/${BRANCH}"
}

commit_local_changes_if_any() {
  if [ -z "$(git status --porcelain)" ]; then
    return 0
  fi

  git add -A
  if git diff --cached --quiet; then
    return 0
  fi

  local commit_message
  commit_message="Auto-sync: $(timestamp)"
  if git commit -m "${commit_message}" >/dev/null 2>&1; then
    log "Committed local changes."
  else
    log "Commit failed. Will retry on next cycle."
    return 1
  fi
}

pull_remote_if_available() {
  if ! git remote get-url origin >/dev/null 2>&1; then
    log "Remote 'origin' is not configured yet."
    return 0
  fi

  git fetch origin "${BRANCH}" --quiet >/dev/null 2>&1 || true

  if ! remote_branch_exists; then
    return 0
  fi

  if git pull --rebase --autostash --quiet origin "${BRANCH}" >/dev/null 2>&1; then
    return 0
  fi

  git rebase --abort >/dev/null 2>&1 || true
  log "Pull/rebase failed (possible conflict). Manual review may be required."
  return 1
}

push_if_ahead() {
  if ! git remote get-url origin >/dev/null 2>&1; then
    return 0
  fi

  local ahead_count
  if remote_branch_exists; then
    ahead_count="$(git rev-list --count "origin/${BRANCH}..HEAD" 2>/dev/null || echo 0)"
  else
    ahead_count="$(git rev-list --count HEAD 2>/dev/null || echo 0)"
  fi

  if [ "${ahead_count}" -gt 0 ]; then
    if git push origin "${BRANCH}" >/dev/null 2>&1; then
      log "Pushed ${ahead_count} commit(s) to origin/${BRANCH}."
    else
      log "Push failed. Will retry on next cycle."
      return 1
    fi
  fi
}

main() {
  if [ ! -d "${REPO_PATH}" ]; then
    echo "Repo path does not exist: ${REPO_PATH}" >&2
    exit 1
  fi

  cd "${REPO_PATH}" || exit 1

  if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "Not a git repository: ${REPO_PATH}" >&2
    exit 1
  fi

  log "Auto-sync started for ${REPO_PATH} on branch '${BRANCH}'."
  log "Polling every ${INTERVAL_SECONDS}s with ${DEBOUNCE_SECONDS}s debounce."

  while true; do
    if is_git_busy; then
      log "Git operation in progress. Waiting..."
      sleep "${INTERVAL_SECONDS}"
      continue
    fi

    local_status_before="$(git status --porcelain)"
    if [ -n "${local_status_before}" ]; then
      sleep "${DEBOUNCE_SECONDS}"

      if is_git_busy; then
        sleep "${INTERVAL_SECONDS}"
        continue
      fi

      local_status_after="$(git status --porcelain)"
      if [ -z "${local_status_after}" ]; then
        sleep "${INTERVAL_SECONDS}"
        continue
      fi

      if [ "${local_status_before}" != "${local_status_after}" ]; then
        log "Changes still moving. Waiting for edits to settle."
        sleep "${INTERVAL_SECONDS}"
        continue
      fi
    fi

    commit_local_changes_if_any || {
      sleep "${INTERVAL_SECONDS}"
      continue
    }

    pull_remote_if_available || {
      sleep "${INTERVAL_SECONDS}"
      continue
    }

    push_if_ahead || {
      sleep "${INTERVAL_SECONDS}"
      continue
    }

    sleep "${INTERVAL_SECONDS}"
  done
}

main "$@"
