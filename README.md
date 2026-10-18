# IT Asset Flow

IT Equipment Assign & Return Management System for an Event Management Company's IT Support team.
Plain **PHP 8 + MySQL + Bootstrap 5 (CDN)** — runs on any cPanel shared-hosting account. No Node, no Composer, no build step.

## Features

- Login with **role accounts** (Admin = full access; User = view + manage, no deletes, no settings), profile pictures, hashed passwords, CSRF protection, idle timeout, security headers, logout confirmation
- **Profile dropdown**: avatar menu with My Profile, Change Password, User Management (admins) and Logout
- **User Management page** (admins): create/edit/activate/deactivate/delete team accounts with role, status, department, employee ID and profile picture; search + filters + pagination
- Inventory CRUD + import from **CSV or Excel (.xlsx)** with preview/validation/duplicate detection + downloadable template (dates accept `YYYY-MM-DD`, `DD/MM/YYYY` or Excel serials)
- **Asset financials**: purchase date, cost, vendor/supplier, invoice #, warranty expiry — with dashboard value total, warranty badges and a daily warranty-alert digest email
- **Asset photos** (PNG/JPG/WebP, max 10 MB) shown in the inventory list and asset page
- **Managed Categories** (admins): created inline from the asset form ("+ Add new category…"); rename propagates to every asset, per-category colour, delete blocked while in use — management lives on the Categories page (linked from Item Icons)
- **Managed Locations** (admins): event/office venues suggested in the assign wizard; renames propagate to past assignments
- **QR asset labels**: printable label sheets per filter/selection — scanning a QR opens the asset page; per-asset QR on the asset page
- **Global search** (top bar, Ctrl+K): assets, users and assignments in one result page
- **Bulk actions** on inventory: select rows → mark Retired / print labels / delete (admins)
- **Duplicate asset** button — clone an asset into the add form with a blank number/serial
- 3-step assign wizard (Select Product → Assignment Details with Purpose Office/Event + Collected By → Confirm & Submit)
- Reports export to **Excel (.xlsx)**, **CSV**, or print view; pagination on inventory, assigns, users, logs
- Assign workflow: user details → dates → searchable multi-select of **Available** assets → confirmation-free single submit (+ optional immediate acknowledgement email)
- Human-readable assign IDs (`ISS-2026-00001`)
- Email acknowledgement with secure single-link token (`acknowledge.php?token=…`, expires after N days)
- Public mobile-friendly acknowledgement page with confirm + "I have a query"
- Returns: per-item Returned / Damaged / Missing, partial returns, automatic inventory status updates
- **Return History page** — every returned/damaged/missing item with search, filters and pagination
- Overdue detection (computed live — nothing to refresh), dashboard alerts, reminder emails with count tracking
- **Due-soon reminder** — one automatic email per assignment N days before the return date (cron)
- Missing / damaged views, user history, full per-asset history
- Reports (inventory / assigns / returns / missing / damaged / users) with CSV export + print view
- **Item Icons**: upload a picture per category, shown on every item of that category
- Activity & email audit log, SMTP settings page, optional cron reminders
- **Dark / light mode** toggle + collapsible icon-only sidebar — both saved per browser

## Deployment (cPanel)

1. **Create the database**: cPanel → MySQL Databases → create DB (e.g. `cpuser_itasset`).
2. **Create a DB user** and give it ALL PRIVILEGES on that DB.
3. **Import the schema**: cPanel → phpMyAdmin → select the DB → Import → `database.sql`.
   **Existing installation?** Import `upgrade_2026-10-11.sql` instead (adds categories/locations tables, asset financial/warranty/photo columns and new settings — run once).
4. **Optional demo data**: Import `sample_data.sql` the same way (skip for production).
5. **Upload the app**: File Manager → `public_html` (or a subfolder) → upload all files (see Structure below).
6. **Configure credentials**: edit `config/config.php` — `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` and set `APP_URL` to the real URL (used in email links).
7. **Permissions**: the `uploads/` folder must be **writable** (category icons + profile pictures are stored there).
8. **Configure email**: log in → Settings → fill SMTP host/port/user/pass (your cPanel email account or a transactional mail service), From name/email, IT support email.
9. **Log in**: `https://yourdomain.com/login.php` — default `admin` / `Fervent@1234`. Changing it is optional but recommended (avatar menu → My Profile → Change Password).
10. **Send a test acknowledgement email** from any assign record; check Logs → Emails if it fails.
11. **Test the flow**: Assign Equipment → open the mail link (or copy the ack link from the assign page) → confirm → Return items → dashboard overdue counters.

## Cron (optional automatic reminders + alerts)

cPanel → Cron Jobs, once per day — **replace `USERNAME`** with your cPanel account name:

```
0 8 * * * /usr/local/bin/php /home/USERNAME/public_html/cron/check_overdue.php
```

The script is CLI-only (denied over the web). Then enable *Settings → Automatic Reminders*. The cron sends at most `Max Reminders per Assignment` emails per assign, never more than one per assign per 7 days. It also sends the **due-soon reminder** (one per assignment, N days before the return date — enable *Settings → due-soon switch*) and the **warranty digest** (one email per day to IT Support listing assets whose warranty expires inside the alert window — set the window in Settings). Overdue status itself is always computed live, so without the cron the dashboard is still correct.

## Local testing (XAMPP)

1. Start Apache + MySQL.
2. phpMyAdmin → Import → `database.sql` (and optionally `sample_data.sql`).
3. Open `http://localhost/it_asset_flow/` → login `admin` / `Fervent@1234`.

## Structure

```
config/config.php        DB + app constants (the only file to edit)
includes/                db, auth, functions, email (SMTP + templates), layout, xlsx
assets/                  css, js (Bootstrap + QR lib come from CDN)
cron/check_overdue.php   optional daily reminders + warranty digest (CLI only)
assign.php               assign wizard (3 steps)
assigns.php              assignment records (search / filters)
assign_view.php          one assignment: details, items, returns, acknowledgement
acknowledge.php          public token page (no login)
asset.php                one asset: history + purchase/warranty + QR
categories.php           managed categories (admin only — linked from Item Icons)
locations.php            managed locations (admin only)
labels.php               printable QR label sheets
search.php               global search (assets / users / assignments)
dashboard.php            overview
inventory.php            assets CRUD + bulk actions + photo + financials
import.php               CSV / Excel import with preview
missing.php              missing equipment
damaged.php              damaged equipment
returns.php              return history
receipt.php              printable return receipt
reports.php              reports + CSV/Excel export
users.php / user.php     customer directory + one user's history
user_management.php      team accounts (admin only)
profile.php              my profile + change password
category_icons.php       item icons per category (admin only)
activity.php             audit + email log
settings.php             company / SMTP / reminders / alerts (admin only)
login.php / logout.php   session endpoints
index.php                redirects to dashboard
database.sql             schema + default admin
upgrade_2026-10-11.sql   one-time upgrade for installations made before 2026-10-11
sample_data.sql          optional demo data (10 assets, 3 assigns: active/overdue/returned)
.htaccess                blocks direct download of .sql/.csv/.md/.log
uploads/                 writable storage: icons/, avatars/, inventory/ (hardened with .htaccess)
```

## Notes & deliberate simplifications

- **8 tables**: the original 6 plus `categories` and `locations` (managed lists). Ack/return/missing/damage data still live as status columns on `assigns` / `assign_items`. Asset photos are files under `uploads/inventory/` (path on the asset row); per-category icons stay file-based under `uploads/icons/`.
- **Excel support** uses a minimal stdlib xlsx reader/writer (`includes/xlsx.php`, ZipArchive + SimpleXML — enabled on virtually all cPanel hosts). It reads the first worksheet with shared/inline strings and numbers; no formulas/styles/merged cells. Legacy binary `.xls` is not supported — save as .xlsx or .csv. Exports neutralize formula injection (`=`,`+`,`-`,`@` prefixes, including tab/CR variants).
- **QR labels** render in the browser via the qrcodejs CDN library — no server-side image code needed (GD is not required). QR content is `APP_URL/asset.php?id=N`, so the phone must be able to reach the same `APP_URL` the emails use. Print the sheet on sticker paper (3 labels per row by default).
- SMTP is a minimal hand-rolled client (SSL/STARTTLS + AUTH LOGIN). If you later need attachments or DKIM, swap in PHPMailer — only `includes/email.php` changes.
- Overdue is computed, not stored — no cron needed for correct dashboards. Due-soon reminders and the warranty digest do need the daily cron.
- Email-open tracking (spec: optional) not implemented.
- SMTP password is stored in the DB in plain text (it must be usable to send mail) — protect DB access accordingly.
- Password change is always **optional**; the only forced change is when an admin explicitly ticks "ask them to set their own password" for an account.
- Further market-standard features (software licenses, consumables, reservations, signature capture, REST API, 2FA) are not implemented — the categories/locations pattern above is the template for adding similar managed lists.
