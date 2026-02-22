# United Wrestling Club Website

Multi-page website for the United Wrestling Club Spring Session 2026.

## Pages

- `index.html` - Homepage (marketing + main call to action)
- `program.html` - Schedule, levels, pricing, and discounts
- `team.html` - Coaches and location
- `contact.html` - Registration updates / waitlist page
- `styles.css` - Shared styles for all pages

## Notes

- Registration is marked as "coming soon" and the waitlist form uses a placeholder `action="#"`.
- Replace the form action in `contact.html` when you have a live registration form/link.

## Edit from multiple computers

Store this project in GitHub, then clone it on any computer and use `git pull` / `git push` to sync changes.

## Auto-sync on macOS (optional)

This repo includes a macOS `launchd` auto-sync service that can automatically:

- detect file changes
- create commits
- pull/rebase from GitHub
- push updates to GitHub

Install on a Mac (after the repo is connected to GitHub):

```bash
bash scripts/install_autosync_macos.sh
```

Stop/remove the service:

```bash
bash scripts/stop_autosync_macos.sh
```

Notes:

- GitHub Pages updates automatically after each push.
- If the repo is inside a protected macOS folder (like `Documents`), the installer will automatically use a session-based auto-sync process instead of `launchd`.
- If two computers edit the same lines at the same time, a manual merge/conflict fix may still be needed.
