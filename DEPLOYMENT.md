# Deploy to Hostinger (easy guide)

Your site can go live on Hostinger **automatically** every time you push code to GitHub on the `master` branch.

**Repo:** https://github.com/AutesJesus/EVSU_RFID_LibraryManagementSystem

---

## The big picture (30 seconds)

1. You write code on your computer (XAMPP).
2. You `git push` to GitHub.
3. GitHub Actions runs a robot that:
   - installs PHP libraries (`composer install`)
   - writes database settings from secret passwords
   - uploads files to Hostinger over **FTPS** (like a secure FileZilla)
4. You open your website in the browser. Done.

You only set up Hostinger + GitHub secrets **once**. After that, every push deploys.

---

## Part A — Hostinger (do this first)

### Step 1 — Log in

1. Open https://hpanel.hostinger.com
2. Log in with your Hostinger account.

### Step 2 — Make sure you have a website

1. In hPanel, click your **hosting plan** (or add one if you do not have it yet).
2. You can use:
   - Your own domain (e.g. `mylibrary.com`), **or**
   - A free test URL like `something.hostingersite.com` (good for first try).

Write down your domain — you will open it in the browser at the end.

### Step 3 — Turn on PHP 8.2

1. hPanel → **Advanced** → **PHP Configuration** (wording may be “Select PHP Version”).
2. Choose **PHP 8.2** (or 8.1).
3. Save.

### Step 4 — Create MySQL database

1. hPanel → **Websites** → your site → **Databases** → **MySQL Databases**.
2. Click **Create new database**.
3. Create a **database name** (copy it somewhere — example: `u123456789_library`).
4. Create a **user** + **password** (strong password).
5. **Add user to database** with **All privileges**.

On a piece of paper (or Notes app), save these **four** lines — you need them for GitHub later:

```text
DB_HOST=     (often "localhost" — hPanel shows the exact host)
DB_NAME=     (the database name you created)
DB_USER=     (the MySQL username)
DB_PASS=     (the MySQL password)
```

**Optional:** If you already have data on XAMPP, export with phpMyAdmin locally, then import the `.sql` file in Hostinger phpMyAdmin.

### Step 5 — Get FTP login (for uploading files)

1. hPanel → **Files** → **FTP Accounts**.
2. Create an FTP account **or** use the main account.
3. Set **Directory** to `public_html` (or the folder where the site should live).
4. Copy these three:

```text
FTP_SERVER=   (hostname, e.g. ftp.yourdomain.com — shown in hPanel)
FTP_USERNAME= (FTP username)
FTP_PASSWORD= (FTP password)
```

5. Test in **FileZilla** (optional but helpful):
   - Protocol: **FTP**
   - Encryption: **Require explicit FTP over TLS** (FTPS)
   - Port: **21**
   - If login works, GitHub will work too.

### Step 6 — Turn on HTTPS (free SSL)

1. hPanel → **Security** → **SSL**.
2. Enable **Free SSL** (Let’s Encrypt) for your domain.
3. Wait a few minutes until it says active.

---

## Part B — GitHub secrets (passwords for the robot)

GitHub Actions must know your FTP and database passwords. **Never** put these inside your code — only in GitHub Secrets.

### Step 7 — Open secrets page

1. Go to https://github.com/AutesJesus/EVSU_RFID_LibraryManagementSystem
2. Click **Settings** (top of repo).
3. Left menu: **Secrets and variables** → **Actions**.
4. Click **New repository secret** for each row below.

| Secret name     | Paste this value              |
|-----------------|-------------------------------|
| `FTP_SERVER`    | Your FTP hostname             |
| `FTP_USERNAME`  | Your FTP username             |
| `FTP_PASSWORD`  | Your FTP password             |
| `DB_HOST`       | Usually `localhost`           |
| `DB_USER`       | MySQL username from Step 4    |
| `DB_PASS`       | MySQL password from Step 4    |
| `DB_NAME`       | Database name from Step 4     |

Add **seven** secrets total. Names must match **exactly** (copy-paste).

### Step 8 — Optional variable (only if deploy folder is wrong)

1. Same page → tab **Variables** → **New repository variable**.
2. Name: `FTP_SERVER_DIR`
3. Value: `./public_html/` (default — use this if unsure)

If your FTP user already opens **inside** `public_html`, try `./` instead.

### Step 9 — Optional “production” environment

The workflow uses environment name `production`.

- If deploy fails saying environment missing: **Settings → Environments → New environment** → name it `production`.
- You can leave protection rules off for solo projects.

---

## Part C — Push code and watch it deploy

### Step 10 — Put deploy files on GitHub

On your PC, in the project folder, commit and push the deploy setup (workflow + config). If someone already pushed this for you, skip to Step 11.

```bash
git add .
git commit -m "Restore Hostinger CI/CD deploy"
git push origin master
```

### Step 11 — Watch GitHub Actions

1. GitHub repo → tab **Actions**.
2. Click **Deploy to Hostinger**.
3. You should see a yellow dot (running) then a **green check** (success).

If you see a **red X**, click the failed job → read the red error line (usually wrong FTP password or wrong `FTP_SERVER_DIR`).

### Step 12 — Open your website

Visit:

- `https://your-domain.com/`  
  or  
- `https://yoursite.hostingersite.com/`

First visit creates database tables automatically (if the DB is empty).

**Check deploy health:** open `https://your-site.hostingersite.com/site-test.php` — green checks mean PHP, files, and MySQL are OK. JSON detail: `health.php`, quick PHP ping: `ping.php`.

**Default admin login** (change password immediately):

- Username: `admin`
- Password: `admin123`

### Step 13 — Email (one-time on server)

GitHub does **not** upload your email password.

1. In Hostinger **File Manager** (or FileZilla), go to `public_html/config/`.
2. Copy `mail.local.php.example` → rename to `mail.local.php`.
3. Edit and paste your Brevo SMTP details.

### Step 14 — Uploads folder

User photos/files go in `uploads/`. The deploy **does not delete** that folder on updates.

If uploads fail, in File Manager set `uploads` permission to **755** or **775**.

---

## Part D — Daily use (after setup)

```text
1. Edit code locally
2. git add .
3. git commit -m "what you changed"
4. git push origin master
5. Wait ~1–2 min → check Actions → visit site
```

Manual deploy without waiting for push: GitHub → **Actions** → **Deploy to Hostinger** → **Run workflow**.

---

## Troubleshooting (simple)

| Problem | Fix |
|---------|-----|
| FTP login failed | Double-check `FTP_SERVER`, username, password in FileZilla with FTPS |
| Database error | `DB_*` secrets must match hPanel exactly; host is often `localhost` |
| Blank white page | PHP version 8.1+; check hPanel **Error log** |
| CSS/images broken | Wrong `FTP_SERVER_DIR` — files must land where `index.php` is |
| 403 on uploads | Fix `uploads/` folder permissions |
| Workflow not running | Push must be to branch **`master`** |

---

## Security checklist

- [ ] Change admin password after first login
- [ ] Never commit `config/db.local.php` or `config/mail.local.php`
- [ ] Never commit `.ssh/` private keys
- [ ] Use strong FTP and MySQL passwords

---

## Local development (XAMPP)

Copy examples (not committed to git):

```text
config/db.local.php   ← copy from config/db.local.php.example
config/mail.local.php ← copy from config/mail.local.php.example
```

Your `db.php` loads `config/database.php`, which reads `db.local.php` overrides.
