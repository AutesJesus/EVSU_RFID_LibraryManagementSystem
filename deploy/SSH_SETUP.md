# SSH deploy key (Hostinger + GitHub Actions)

Keep the project `.ssh/` folder on your PC. It is gitignored (not uploaded to GitHub) but required for deploy keys.

## 1. Add public key to Hostinger

hPanel → **Advanced** → **SSH Access** → **Add SSH key**

Paste the full line from `.ssh/hostinger_github_actions.pub` (starts with `ssh-ed25519`).

Enable SSH if it is off. Note the SSH port (usually **65002**).

## 2. Add GitHub Actions secrets

Repo → **Settings** → **Secrets and variables** → **Actions**

| Secret | Value |
|--------|--------|
| `SSH_PRIVATE_KEY` | Entire contents of `.ssh/hostinger_github_actions` |
| `SSH_HOST` | `145.79.14.96` |
| `SSH_USER` | `u710415762` |
| `SSH_PORT` | `65002` |
| `SSH_REMOTE_PATH` | `domains/coral-seahorse-316772.hostingersite.com/public_html/` |

## 3. Deploy

Push to `main` or run **Actions** → **Deploy to Hostinger** → **Run workflow**.

Log should show: `Deploy method: SSH/rsync`.

**Never commit** `.ssh/hostinger_github_actions` (private key). Only the `.pub` file is safe to share.

**Do not delete** the `.ssh/` folder from your project directory.
