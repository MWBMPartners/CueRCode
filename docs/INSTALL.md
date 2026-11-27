# CueRCode: fresh install runbook

Written 2026-11-25 (queue task B8, #113). One ordered list, from an empty DreamHost account to "I can sign up, get the verification email and make a QR code".

**How much of this has been tested?** Every step was checked against the code, the README, `DEV_NOTES.md` and the admin screens as they exist in this repository. **None of it has been run on a live DreamHost server yet** (the dry run is queue task B9, #13). Anything that depends on DreamHost's own panel, server paths or Microsoft's website is marked **verify on your panel / verify live**. Where this guide and the real panel differ, trust the panel and then fix this guide.

**Plain-English key** (terms used below):

- **SFTP**: a secure way to copy files to a server. GitHub does this for you on every push.
- **Cron job**: a job the server runs by itself on a timer (here, every minute).
- **Migration / database upgrade**: a small numbered change to the database (a new table or column). They are applied in number order and are safe to run again.
- **Global Admin**: the one kind of account that can open `/admin/`.
- **Command line / SSH**: typing commands on the server itself, through DreamHost's shell login.
- **Cloudflare**: a company that sits in front of some websites. If you use it, visitors reach Cloudflare first and it passes them on to your server.
- **HSTS**: a header that tells browsers "only ever use HTTPS for this site, for a long time". Browsers remember it, so it is hard to undo.

> **Owner-only steps.** An assistant cannot do these for you. Each is also written in the step where it belongs.
>
> 1. Choose and pay for the DreamHost plan; create the domain, database and SSH user (steps 0 to 2).
> 2. Create the GitHub secrets `SFTP_HOST`, `SFTP_PORT`, `SFTP_USER`, `SFTP_KEY` (or `SFTP_PASSWORD`), `SFTP_REMOTE_BASE`, `SFTP_KNOWN_HOSTS` and the variable `SFTP_ENABLED` (step 1).
> 3. Put `auth_creds.php` on the server, with your own database password and encryption key (step 2).
> 4. Set the website address, then create the Microsoft 365 app registration and shared mailbox, and type the details into `/admin/settings/` (step 6).
> 5. Add the DreamHost cron job (step 7).
> 6. Decide when to switch on the HTTPS redirect (step 8).
> 7. Anything in step 10 (passwords for the alpha and beta sites, bot-protection keys, key rotation).

---

## 0. What you need before you start

| You need | Detail |
| --- | --- |
| A DreamHost plan with SSH and cron | Shared hosting is what this is built for. You need SSH (shell) access for steps 4 and 5. **Verify on your panel** that your user has "Shell" access (Websites, then Manage Websites, then the user). |
| PHP **8.4, 8.5 or 8.6** with 8 extensions | `mysqli` (database), `mbstring` (text in any language), `gd` (images), `json`, `openssl` (encryption), `zip` (bulk downloads), `bcmath` (PDF417 barcodes), `curl` (email and sign-in providers). The installer checks all eight and says which is missing. `imagick` is optional. Decision 0002. |
| MySQL **8.0.13 or newer**, or MariaDB **10.6 or newer** | Decision 0012. The installer refuses anything older before it creates anything. MariaDB 10.6 has had the test suite run against it, but a full fresh install on it has not (B9). |
| Apache with `mod_rewrite` | DreamHost has it. |
| A domain pointed at DreamHost | Primary is CueRCode.net. Alpha and beta can use any hostname you choose. |
| A Microsoft 365 shared mailbox and an app registration | Sign-up emails go through Microsoft 365 (not PHP's `mail()`). See step 6 for exactly what to create. You can install and look around without it, but nobody can verify an email address until it works. |
| A GitHub repository with Actions switched on | The deploy runs there. |
| A computer with `ssh-keyscan` (Mac and Linux have it) | To produce the `SFTP_KNOWN_HOSTS` value (step 1). |

---

## 1. Put the files on the server

### 1a. Automatic upload (the normal way)

**Nothing is cloned on the server.** GitHub Actions (`.github/workflows/deploy.yml`) uploads the files over SFTP whenever the `alpha`, `beta` or `main` branch is pushed, and only when the repository variable `SFTP_ENABLED` is `true`. It first runs the PHP checks on 8.4, 8.5 and 8.6 and will not upload if they fail. (As of today it has never run a real upload: every run so far was skipped because the switch was off.)

Which branch uploads which public folder (the private `_` folders go up with every branch):

| Branch | Public folder it uploads | Point this domain at it |
| --- | --- | --- |
| `main` | `public_html/` | the live site (CueRCode.net) |
| `beta` | `public_html_dev_beta/` | the beta site (hostname is your choice) |
| `alpha` | `public_html_dev_alpha/` | the alpha site (hostname is your choice) |

In the DreamHost panel set each domain's **web directory** to the matching folder (verify on your panel: Websites, then Manage Websites). The alpha and beta folders ship **locked to everyone** (`Require all denied`, DS-1) until you set up a password for them (step 10), so a 403 "Forbidden" there is expected at first.

**Set these in GitHub (owner-only), under Settings, then Secrets and variables, then Actions:**

| Name | Kind | What to put |
| --- | --- | --- |
| `SFTP_HOST` | secret | The SFTP server name DreamHost gives you. |
| `SFTP_PORT` | secret | Usually `22`. |
| `SFTP_USER` | secret | The SFTP/shell user name. |
| `SFTP_KEY` | secret | The private SSH key for that user (preferred). |
| `SFTP_PASSWORD` | secret | Only used when `SFTP_KEY` is empty. |
| `SFTP_REMOTE_BASE` | secret (repository) | The server folder that **holds** the `CueRCode.net` folder, a full path starting with `/`, for example `/home/your-user`. The upload refuses to run if it is empty, not a full path, or just `/` (#138). |
| `SFTP_KNOWN_HOSTS` | secret | The server's identity, so GitHub can tell the real DreamHost server from an impostor. Without it the upload **refuses to connect**. How to make it: below. |
| `SFTP_ENABLED` | **variable** (not a secret) | `true`. The master switch. Leave it unset to keep the deploy off. |

**Making `SFTP_KNOWN_HOSTS`:** on a computer you trust, run `ssh-keyscan -p 22 your-sftp-host` (use your real host and port). Check that the fingerprint matches DreamHost's own records for that server, then paste the printed lines as the secret. Detail is in `DEV_NOTES.md`, "CI/CD".

### 1b. First-time manual upload (if you are not using GitHub yet)

Copy the contents of `web/CueRCode.net/` to `~/CueRCode.net/` on the server with any SFTP program: the private folders (`_auth_keys`, `_functions`, `_includes`, `_libraries`, `_uploads`, `_backups`) and the public folder for this site (`public_html/` for live). Do not upload `README.md`, `CHANGELOG.md` or `DEV_NOTES.md` (the deploy leaves them out). Make sure `_uploads` and `_backups` exist and are writable by the web user. The QR, PDF and barcode libraries are already in `_libraries/` (decision 0011), so there is nothing else to download.

---

## 2. Create the database and the credentials file

1. In the DreamHost panel create a MySQL database, a user and a password (verify on your panel: Websites, then MySQL Databases). Note the **database host name** you choose. It is not always `localhost`.
2. On the server, in `~/CueRCode.net/_auth_keys/`:

   ```bash
   cp auth_creds.example.php.dist auth_creds.php
   chmod 640 auth_creds.php
   ```

   (`auth_creds.example.php.dist` is the template that comes with the deploy. `auth_creds.php` is never in git and a deploy never overwrites it.)
3. Edit `auth_creds.php` and replace every `REPLACE_ME`: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and `ENCRYPTION_KEY`. Make the key with `openssl rand -hex 32` (64 hex characters). **Keep a safe copy of the key** somewhere other than this server: if it is lost, saved secrets (such as the email app secret) cannot be read back and must be typed in again.

---

## 3. Run the installer once, then lock it

The folder `public_html/install/` ships with an `.htaccess` that **denies all web access**, on purpose. To run the installer:

1. On the server rename `install/.htaccess` to `install/.htaccess.off` (SFTP program or File Manager).
2. Open `https://your-domain/install/`. Every prerequisite line must be green: PHP version, the eight extensions, the credentials file, `_uploads` and `_backups` writable, and the lock file location. Press **Install Database**. It checks the database version, creates the tables, loads the starting data, **applies the numbered database upgrades** (so step 4 is already done on a brand-new install), and writes a lock file at `_auth_keys/.installed.lock`.
3. **Close it again straight away:** rename `install/.htaccess.off` back to `install/.htaccess`.
4. **Do not delete the `install/` folder** (the next deploy would upload it again, and older installs kept the lock inside it). Keep the folder with its deny-all `.htaccess`, and keep `_auth_keys/.installed.lock`. Check the `.htaccess` is back after every deploy.

---

## 4. Apply database upgrades (and why straight after every deploy)

On a **brand-new install the installer has just done this**. Confirm with:

```bash
php ~/CueRCode.net/_includes/cli/migrate.php --status
```

(Use your command-line PHP: see step 7 for finding it. Exit code 0 means up to date; 3 means upgrades are waiting.)

On an **existing site that has just received new code**, apply upgrades **immediately after the deploy**, either with `php .../migrate.php --apply` or as a Global Admin at `/admin/upgrades/` (it asks for your password and a tick for "I have a recent backup").

The upgrades so far are `0001` (price snapshot columns), `0002` (admin change log and settings), `0003` (sessions) and `0004_plans_prices_currencies` (plans and prices: until it is applied the pricing page shows the old prices without the Enterprise card, and the plan screens and preview ask for the upgrade).

**Why straight away, with `0003_sessions_valid_from` as the example:** that upgrade adds two empty columns that let a password reset cancel sign-ins that were part-way through. Until it is applied the new code still lets people sign in normally (so the admin can still reach `/admin/upgrades/`), but that protection is **switched off**. So there is a window where the site is less safe than it looks. The same principle applies to every later upgrade: deploy, then upgrade, without leaving it for later. Admin pages that save changes also refuse to save while any upgrade is waiting.

---

## 5. Register, then make the first Global Admin

No web page can make the first admin (a "first visitor becomes admin" page would let a stranger claim a fresh site before you). It is done on the server.

**Why the command line is the way in (read this once).** On a fresh install email is off, so no verification email reaches you. The verification link exists only inside the waiting email's row in the email queue table (`tblEmailQueue`), and the queue wipes a link as soon as the email is sent or skipped (#122), so it cannot be fetched from there later. **If you cannot read `tblEmailQueue` (no phpMyAdmin), the owner reaches the first admin only through the command line** (SSH, step 5.2). With phpMyAdmin but no SSH, see the last-resort route at the end of this step.

1. Open `https://your-domain/register/` and sign up with **your own** email address and a password. The account stays **pending**. Signing up also **queues** a "please verify your email" email for that address. Nothing sends it yet because email is off.
2. Over SSH, run (replace the address with yours):

   ```bash
   php ~/CueRCode.net/_includes/cli/admin_access.php grant you@example.com --activate
   ```

   Use the command-line `php`, not `./admin_access.php` (the deploy does not keep execute permissions).
3. It shows the account's email, display name and **when it was registered**. Check that is you and that time. **If you did not register this account at that time, stop.** A stranger may have signed up with your address first (decision 0010).
4. Type your email address again to confirm.
5. It asks for a **new password**, twice. Your typing is hidden. This needs a real terminal (an SSH session you are typing in); from a script it refuses. The new password replaces the one the account was registered with, and every earlier way into the account is removed: signed-in sessions, API keys, unused password-reset links, linked Microsoft or Google sign-ins, and passkeys.
6. **The waiting emails for that account are cancelled for you.** In the same step, `grant --activate` marks any verification or password-reset email still waiting in the queue for that address as skipped (it would only arrive later, when you turn email on, pointing at an account that is already verified). The command prints how many it cancelled (for example "Waiting emails cancelled: 1 verification, 0 password-reset."). An email that was already being sent at that moment, or already sent, is not affected: if one such email does arrive, ignore it.
7. Check with `php .../admin_access.php list` (addresses are partly hidden).

**Last resort only, if you have no SSH at all (phpMyAdmin):** `DEV_NOTES.md`, "Admin area and the first Global Admin", has the exact SQL. Read its warnings first. This route **cannot set a safe password**: it keeps whatever password the account was registered with, so if a stranger registered your address first, their password becomes an admin password, and earlier sign-ins are not ended. If you use it, check `createdAt` and the display name first, remove anything a stranger could have added (API keys, linked sign-ins, sessions, reset links), and set a fresh password at `/account/` straight after. If your own password does not sign you in, someone else registered first: remove the role at once and use the command line. This route does **not** cancel the waiting verification email (only the command line does): when you turn email on it will be sent, pointing at an already-verified account. Ignore it.

---

## 6. Sign in, look around, and turn email on

1. Sign in at `/login/` with the **new** password, then open `/admin/` (or "Admin" in your account menu). Anyone who is not a Global Admin sees an ordinary "page not found".
2. **Confirm password.** Every change needs your password again if you have not typed it within 15 minutes, and at most every 60. The settings page has an **Unlock editing** button for this.
3. **Visitor address check** (admin overview). It shows the address your own request arrived from, which header was used, and whether the connection is inside a Cloudflare range. Compare "address the site will use" with what a "what is my IP" website shows for you. **If the site is behind Cloudflare**, also compare the built-in Cloudflare list with the current one at <https://www.cloudflare.com/ips/> and read any warning shown (decision 0014). The command-line version, `php .../visitor_address.php`, only shows the settings and can test a pretend request. It cannot see a real visitor.
4. **Set the website address (before you turn email on).** In `/admin/settings/`, the first box, **Website address**, ("Website address used in emailed links"), holds the address of the site. Every link in an email (verify your email, reset your password) and the Microsoft and Google callback addresses shown on the settings page are built from it. It starts as `https://cuercode.net`. **The value lives in the database and is shared by every copy of the site that uses the same database.** With the standard layout (one folder, one credentials file, and the alpha and beta test sites deployed into the same folder) it must stay the LIVE address; changing it would point the live site's emails at the wrong place. A test site may have its own value only if it has its own database (its own folder and credentials file). On a fresh install on another domain, type that domain, for example `https://www.example.com`, press **Save website address**, and check that the callback addresses shown in the sign-in section now start with your address. Rules: it must start with `https://`; only the website name (no page path, no `?` or `#` part, no port, no user name); no IP number, `localhost` or internal-only names (`.local`, `.internal` and similar); a trailing `/` is removed for you. **If Microsoft or Google sign-in is switched on, register the new callback addresses with them first, or sign-in will stop working; sign-ins in progress during the change also fail.** The address is also used for the page's main-address and share-preview tags and the example addresses on the developer pages. The change is recorded in the admin audit log like any other setting.
5. **Create the Microsoft 365 side** (owner-only, in Microsoft's admin pages; menu names may change, so verify live):
   1. A **shared mailbox** the site will send as, for example `notifications@yourdomain`.
   2. An **app registration** (Microsoft Entra ID, then App registrations, then New registration).
   3. On it, add the **`Mail.Send` application permission** (application, not delegated) and have a Global Admin of the Microsoft tenant **grant admin consent**.
   4. Under Certificates and secrets, create a **client secret** and copy its **Value** (not the Secret ID) at once; Microsoft shows it only once.
   5. Copy the **Directory (tenant) ID** and **Application (client) ID** from the app's Overview page.
   6. Set up DKIM and DMARC for the sending domain so that receivers do not mark the mail as spam.
6. Open `/admin/settings/`, press **Unlock editing** and type your password. In the **Email (Microsoft 365)** section fill in the tenant ID, the app (client) ID, the app secret, the sending mailbox and the sender name, then press **Save email settings**. A secret box is always shown empty; leaving it empty keeps what is saved; the saved value is never shown again.
7. Press **Check Microsoft connection**. It must say **Connected**. If not, re-check the three Microsoft details (the page never shows Microsoft's own error text).
8. Turn on **Send emails**, tick the confirming box and save. Emails already waiting are now allowed to go, but **nothing is actually sent until the email queue job in step 7 is running** (or, without a job, the "send queued emails when someone visits a page" option in step 7.5 is on).
9. Press **Send me a test email**. It goes to your own address only, needs email switched on, and is limited to 5 in 10 minutes. **It will not arrive until the cron job in step 7 exists**, because the site only queues emails and a separate job sends them.

---

## 7. The email queue cron job (DreamHost)

Sign-up and password-reset emails are only **queued** by the site. Without this job nobody gets them.

1. **Find your command-line PHP path.** Over SSH try `which php` and `ls /usr/local/php*/bin/php` (DreamHost keeps one folder per PHP version). The code comments suggest `/usr/local/php84/bin/php`. **Verify live.** Check it is 8.4 or newer with `<path> -v`. The command-line PHP can differ from the PHP the website uses.
2. **Test by hand first** (nothing is sent):

   ```bash
   /usr/local/php84/bin/php ~/CueRCode.net/_includes/cron/process_email_queue.php --check
   ```

3. **Know what the exit code tells you.** Exit code 0 means "the run finished normally". It does **not** mean every email was delivered: if sending an email fails this time but it has attempts left, the runner tries again on a later run and still ends with 0 (without `--quiet` its summary line says "will retry N"; with `--quiet`, as in the cron command below, it prints nothing for that). Exit code 4 means sending is blocked (settings or Microsoft) and 5 means it gave up on at least one email after all its attempts. The runner's printed output never has email addresses in it, but the site's activity log in the database does keep the full recipient address of each queued and sent email.
4. **Add the job** in the DreamHost panel (menu names: **verify on your panel**; DEV_NOTES says Advanced, then Cron Jobs, then Add New Cron Job). Pick your shell user, a title such as "CueRCode email queue", and **every minute** (if the panel's shortest interval is longer, emails simply wait that long). Command, with the full path to your home folder:

   ```
   /usr/local/php84/bin/php /home/YOUR-USER/CueRCode.net/_includes/cron/process_email_queue.php --quiet
   ```

   - Set **Email output** to your own address. With `--quiet` it prints nothing on a normal run, so you are only emailed when something needs attention. If sending is blocked you will get an email every minute; pause the job while you fix it.
   - If the panel offers **"Use locking"**, leave it **unticked**. The runner has its own lock.
5. No cron at all? On `/admin/settings/` (Email section), the box "Also send queued emails when someone visits a page" (`email_queue_autoprocess`) makes the site send up to 2 emails at the end of ordinary page visits. It is off by default because it slows pages and stalls on a quiet site. See DEV_NOTES, "Email queue runner".

---

## 8. HTTPS redirect (switch on only when HTTPS is proven)

**What is there now.** In `public_html/.htaccess` (inside the "Force HTTPS" block) these two lines are commented out with `#`:

```apache
# RewriteCond %{HTTPS} off
# RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

Together they say: if the visit is not over HTTPS, send the visitor to the same address on `https://`, with a **permanent (301)** redirect (browsers and search engines remember a 301). They are off so local plain-HTTP testing keeps working. The HSTS header further down the file is already active, but is only sent on HTTPS replies, so it does nothing until the site is on HTTPS.

**Switch on when:** the domain has a **valid TLS certificate** (DreamHost issues a free Let's Encrypt one: verify on your panel) and `https://your-domain/` loads with no browser warning, **and** you have signed in and used the site over HTTPS. Do it at go-live, not before. The alpha and beta sites have their own `.htaccess`; this change is for the live `public_html/` only.

**Switch-on steps:**

1. In `web/CueRCode.net/public_html/.htaccess` delete the leading `# ` on those two lines only.
2. Commit it to `work-in-progress`, and let it reach `main` through the normal pull-request route (or, for an emergency on the server only, edit the file there; the next deploy overwrites it with whatever git holds, so also make the git change).
3. Test: `curl -I http://your-domain/` should show `301` and `Location: https://your-domain/...`; then `curl -I https://your-domain/` should show `200` and a `Strict-Transport-Security` header.

**Rollback:** put the `# ` back on both lines and deploy again. Because browsers cache 301s, someone who already followed one may keep being sent to `https://` for a while (clearing site data fixes that for them).

**HSTS caution.** The active HSTS line asks browsers to refuse plain HTTP for **6 months** (15768000 seconds) and includes all subdomains. Once a browser has seen it over HTTPS, switching the redirect off will **not** make that browser use HTTP again. If your certificate then expires, visitors get a hard, unskippable error. Do not enable the commented-out **preload** variant (it is shipped inside browsers and can take months to remove) unless the owner has confirmed every current and future subdomain will always serve valid HTTPS (see `.htaccess` and `SECURITY.md` F-002).

---

## 9. Prove sign-up works from end to end

1. In a private browser window (or another browser) open `/register/` and register a **second test account** with an email address you can read.
2. Within a minute or two (the cron job runs every minute) you should receive the verification email. If not, go to the troubleshooting table.
3. Open the link. The **verify page asks for the password you chose when signing up** (decision 0013). Type it and press **Verify my email**. You should see a success message and a **Sign in** button.
4. Try the recovery paths: open `/resend-verification/` ("Send me a new verification link") with that or another test address and check the new email arrives (the page gives the same reply for any address, on purpose, and is rate limited). If you did not choose the password, or have forgotten it, use **Forgot password**: it emails a link that sets a new password and activates the account.
5. Sign in as the test user and **generate a QR code** from the home page; download it.
6. As the admin, open `/admin/` and confirm the Email panel looks healthy. Optionally delete the test account at `/account/`.

---

## 10. After the install: lock-down checklist

- [ ] `install/` is still there with its deny-all `.htaccess` (not renamed `.off`, not deleted) and `_auth_keys/.installed.lock` exists.
- [ ] `_auth_keys/auth_creds.php` permissions are `640` (readable by you and the web user's group only), and `_auth_keys/` has its deny-all `.htaccess`. The private folders (`_includes`, `_functions`, `_libraries`, `_uploads`, `_backups`) each ship with a deny-all `.htaccess` and sit outside `public_html`, so they have no web address; check the `.htaccess` files arrived after the first deploy.
- [ ] **Dev alpha and beta sites (DS-1):** both ship denying everyone. To let your testers in, create an `.htpasswd` file on the server and follow the numbered steps inside `public_html_dev_alpha/.htaccess` and `public_html_dev_beta/.htaccess`. Until then they are closed. Do this before pointing anything public at them.
- [ ] **Bot protection keys** (Cloudflare Turnstile, then reCAPTCHA, then hCaptcha) go into `/admin/settings/` once you have decided which to use; until then sign-up has no bot check beyond the built-in limits.
- [ ] Legal pages: replace the template wording in Terms and Privacy (#38) before public use.
- [ ] Back up `ENCRYPTION_KEY` and make a database backup routine.
- [ ] **Rotate the SFTP key** from time to time: make a new key pair, add the public half to the DreamHost user, change the `SFTP_KEY` secret, then remove the old public key. Re-run `ssh-keyscan` and update `SFTP_KNOWN_HOSTS` only if the server itself changed.
- [ ] HTTPS redirect: step 8, at go-live.

---

## 11. Troubleshooting (real messages)

| What you see | Where | What it means and what to do |
| --- | --- | --- |
| `Deploy stopped: the server's SSH identity (host key) is not pinned ...` | GitHub Actions log | `SFTP_KNOWN_HOSTS` is empty. Make it (step 1) and save it as a secret. |
| `Deploy stopped: SFTP_REMOTE_BASE is missing or isn't a full folder path ...` | GitHub Actions log | Set `SFTP_REMOTE_BASE` to the folder that holds `CueRCode.net`, starting with `/`, not just `/`. |
| Deploy runs show as skipped | GitHub Actions | `SFTP_ENABLED` is not `true`. |
| Actions jobs "fail" in about 2 seconds with no log | GitHub Actions | GitHub did not start them (billing or minutes). It is not a code fault: run the checks locally. |
| Blank 403 Forbidden at `/install/` | Browser | Expected: the deny-all `.htaccess` is in place. Do step 3.1. |
| `Installation was refused before anything was changed. Fix the problems above, then try again.` | Installer | A prerequisite line is red. Fix what it names. |
| `The PHP "xyz" extension is missing. ...` | Installer | Ask DreamHost or enable it in the panel's PHP settings (verify on your panel). |
| `The folder "_uploads" does not exist ...` or `... the website is not allowed to write to it` | Installer | Create the folder next to `public_html` and make it writable. |
| `Missing required database constants: ... Please check your auth_creds.php file.` | Installer | A `REPLACE_ME` is still there or a line was deleted. Re-edit the file. |
| `Database connection failed. Please verify your credentials ...` | Installer | Wrong host, name, user or password in `auth_creds.php`. The DreamHost host is the one you picked in the panel, not always `localhost`. |
| `This database (...) is too old. CueRCode needs MySQL ...` | Installer | Use MySQL 8.0.13+ or MariaDB 10.6+ (decision 0012). |
| `Seed data skipped: this database already holds all of the starting data` | Installer | Informational: you re-ran it on a database that was already filled. Nothing was duplicated. |
| `Seed data skipped: this database says the starting data was already loaded (the seed_data_loaded setting is there)...` | Installer | Informational: the database remembers the starting data was loaded, so the installer leaves it alone, even if you have since emptied or changed the QR types, logos or plans. To force a clean re-seed on purpose: delete the row with `settingKey` = `seed_data_loaded` from `tblSettings`, empty `tblQRCodeTypes`, `tblPredefinedLogos` and `tblSubscriptionPlans`, then reload the install page. |
| `This database holds only PART of the starting data (...)` | Installer | Some starting rows are there but not all, so it stops. On a NEW database, empty `tblQRCodeTypes`, `tblPredefinedLogos` and `tblSubscriptionPlans` (or start with an empty database) and press install again. On a site ALREADY IN USE, empty nothing: put `_auth_keys/.installed.lock` back instead. |
| `The starting data was not loaded, and nothing was written, because it can only be loaded all-or-nothing into ordinary InnoDB tables: ...` | Installer | A starting-data table uses another storage type (for example MyISAM). Run the `ALTER TABLE ... ENGINE=InnoDB;` line the message gives (phpMyAdmin, SQL tab), then press install again. |
| `This database had some database upgrades applied from an earlier version of their files, and those files have changed since: ...` | Installer | The database was upgraded with an older copy of an upgrade file (so far only possible with the 26 November 2026 version of upgrade 0004, on a developer's or throwaway database; decision 0036). Nothing was changed and the installer is not locked. Delete the database, create it again empty (or point the site at a new empty one), then reload the installer page. |
| `The installer could not check which database upgrades this database already has, so it has not started and nothing was changed.` | Installer | See the PHP error log for the detail (for example a broken file in `_includes/sql/migrations/`), fix it, then reload the page. |
| `Database upgrades did not finish, so installation has stopped here.` | Installer | See the PHP error log for the detail; fix and press install again (steps are safe to repeat). |
| `A database upgrade is running against this database at this moment, so the starting data was not loaded and nothing was changed.` | Installer | Someone is running `migrate.php` (or `/admin/upgrades/`) on the same database. Wait for it to finish, then reload the installer page. |
| `Error loading the starting data (seed_data.sql, with the starting-data parts of any database upgrades already applied). Nothing from it was kept...` | Installer | The starting data and the matching parts of upgrades already applied (for example the Enterprise plan, when `migrate.php` was run before the installer) load together or not at all. See the PHP error log, fix the problem, then reload the page. |
| `The database is installed, but the installer could not write its lock file, so it is NOT switched off.` | Installer | Make `_auth_keys/` writable and create `_auth_keys/.installed.lock`, or put the `.htaccess` deny-all back on `install/`. **The message also suggests deleting the `install/` folder. Do not**: a later deploy puts it back without the lock. Keep the folder with its deny-all rule. |
| `Invalid or missing CSRF token. Please reload the page and try again.` | Installer | Reload `/install/` and try again. |
| `CueRCode admin access: could not start - the site start-up code stopped first ...` | Command line | Missing or unreadable `_auth_keys/auth_creds.php`. |
| `No account has that email address. Sign up at /register/ first ...` | `admin_access.php` | Register first (step 5.1), with exactly this address. |
| `Nothing was changed: this account is not active with a checked email address yet. Add --activate ...` | `admin_access.php` | Add `--activate` (you then set a new password). |
| `That account has no password (it signs in with Microsoft or Google only) ...` | `admin_access.php` | Use "Forgot password" to give it a password first. |
| It refuses before asking for a password | `admin_access.php` | It needs a real terminal that can hide typing (an SSH session, not a script or pipe). |
| `Nothing was changed: no acceptable new password was typed after 3 tries.` | `admin_access.php` | Run again and pick a password that meets the site rules. |
| `Access denied.` | Any `_includes/cli` or `cron` file | You ran it through the web or `php-cgi`. Use the command-line PHP. |
| Exit code 3 from `migrate.php --status` | Command line | Upgrades are waiting. Run `--apply`. |
| Check says "Could not connect" | `/admin/settings/` | Tenant ID, client ID or secret wrong; the `Mail.Send` permission lacks admin consent; or the secret expired. Re-enter the secret **Value**. |
| Test email says sending is off | `/admin/settings/` | Turn on **Send emails** first. |
| No verification email | Inbox | Is "Send emails" on? Is the cron job running (`process_email_queue.php --check`)? Look in spam. Cron exit code 4 means sending is blocked by settings or Microsoft; 5 means it gave up on an email. **Exit code 0 can still hide a failed delivery that will be retried** (the summary line says "will retry"; `--quiet` hides it): wait a few minutes, or run the runner once without `--quiet`. Re-sending given-up emails: `DEV_NOTES.md`, "Email queue runner". |
| Verification link says the password is wrong or the link is too old | Verify page | Use **Send me a new verification link**, or **Forgot password**. |
| `This link has already been used or has been replaced by a newer one. Please use the newest email we sent you, or ask for a new link.` | Verify page | Either the link was already used, or you asked for a newer link (that retires the older ones). Open the newest email, or use **Send me a new verification link**. |
| Verification or reset emails link to the wrong website | Inbox | The **Website address** setting holds a different address from the one you expect. Check it in `/admin/settings/` (step 6.4); remember all copies sharing one database share one value. Emails already queued keep the address they were made with; ask for a new link. |
| An API key gives `403 INSUFFICIENT_SCOPE` on every call | API client | The key was created with no scopes ticked, so it can do nothing. A key's scopes cannot be changed later. **Revoke** it and create a new one with at least one scope ticked (for example **Generate**). The free plan allows one active key, so revoke first. Rotating a key keeps its (empty) scopes and does not help. |
| `/admin/` shows "page not found" | Browser | The account is not a Global Admin, not active, or has no checked email. |
| Settings page: "Database upgrade needed" | `/admin/settings/` | Apply upgrades (step 4). |

---

## 12. What is NOT ready yet

Do not promise any of these to anyone. This install is "ready to try", not "ready for the public".

- **Taking payments.** PayPal fields exist on the settings page but charging is locked off.
- **Dynamic QR codes** (codes you can change after printing). Locked off (`dynamic_qr_enabled`).
- **Discounts, lifetime deals, pay-as-you-go and feature screens** are later phases (7n Phases 3 to 7). Plans and prices can already be edited at `/admin/plans/` (database upgrade 0004).
- **Legal pages.** Terms and Privacy are still templates (#38, owner plus legal advice).
- **Not yet tested on a live DreamHost server:** this whole guide (B9, #13, #17, #18, #22), the cron job, Microsoft 365 sending against a real mailbox, MariaDB 10.6 for a full fresh install, and the deploy workflow.
- **Bot protection** keys are not decided (see step 10).
