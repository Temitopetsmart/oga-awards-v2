# Repository Audit — OGA Awards

Status: discovery complete for the areas listed below. Nothing was executed or tested. No repository files were changed.
Labels: **[V]** verified by reading source · **[I]** inference · **[P]** proposal · **[?]** unresolved.

## 1. Repositories

| Repo                    | Stack                                                             | Files read                                                                 |
| ----------------------- | ----------------------------------------------------------------- | -------------------------------------------------------------------------- |
| `oga-awards` (original) | Plain PHP, PDO/MySQL, sessions, Flutterwave, PHPMailer, Bootstrap | ~751 non-git files, ~21,800 lines excluding QR-code cache and vendor files |
| `oga-awards-v2`         | Static HTML, JS modules, Bootstrap, localStorage demo services    | 151 files                                                                  |

## 2. Inspected vs not inspected

**Original, read:** `process_vote`, `verify_payment`, `flutterwave_webhook`, `reconcile_pending`, `connect`, `password`, `revoke`, `auth/_login`, `auth/_register`, `auth/verify_registration`, `auth/mail`, `admin/login`, `admin/votes` (guard), `admin/addvote` (SQL + guard), `admin/committee`, `admin/delete-category`, `admin/edit-category`, `ticket/ticket_webhook`, `ticket/save_ticket_pending`, `ticket/ticket-admin/scanner` (check-in), `results` (ranking query), both SQL dumps (columns, keys, foreign keys), and a guard check of all `admin/*.php`.
**Original, not read:** `voting_page`, `category_vote`, `vote`, `miss_ogitech*`, `verify_miss_payment`, `committee_apply`, `nominee/*`, `auth/register`, `auth/select_account`, `ticket/index|retrieve|verify|verify_ticket`, `ticket-admin` dashboard/settings/tickets, `admin/nominees|add_nominee|nominee_profiles|results|categories|setting|dashboard|miss_ogitech_admin`, all `.htaccess` files, `error_log` contents, `ssl/` contents beyond file names, `vendor/`.
**V2, read:** `vote|payment|ticket|auth` services, `http.js`, `scope.js`, `admin-auth.service`, `permissions.js` (structure), `edition.repo` (lifecycle), `team.repo` (guards), `data/editions.json`.
**V2, not read:** `committee-recruitment.service`, `invitation.repo`, `nominee.repo`, `category.repo`, `catalog.service`, `event.service`, `forms.service`, admin page modules, all site page modules and HTML.

## 3. Verified: original system

**Payments (good, preserve)**

- [V] Votes, tickets and Miss OGITECH fees are confirmed through Flutterwave's verify API. Checks cover `status`, `tx_ref`, amount (`>=`) and `NGN`.
- [V] Credit is idempotent: `UPDATE … WHERE payment_status='pending'` and credit only if `rowCount() > 0`.
- [V] Three confirmation paths exist: redirect page, signed webhook (`verif-hash`, `hash_equals`) and manual `reconcile_pending.php`.

**Payment and voting defects**

- [V] Status update and `nominees.votes += n` are separate statements with no transaction. A crash between them leaves a paid order that never counts, and reconcile skips it.
- [V] Vote totals are a mutable counter (`nominees.votes`), not derived from `votes` rows. Admin manual votes also write to it.
- [V] `votes.reference` has no UNIQUE key (tickets and registrations do). The reference is `OGA_`.`time()`, so same-second orders collide.
- [V] Limit rule in code: ≤50 votes per payment and `COUNT(*) >= 5` **paid orders** per email and category. It ignores pending orders and is not atomic, so concurrent orders bypass it.
- [V] `process_vote.php` does not validate that the nominee exists, that the email is valid, or that voting is open. Price (100) and limits are hardcoded. There is no CSRF protection.
- [V] `votes.nominee_id` has `ON DELETE CASCADE`: deleting a nominee deletes its paid vote records.
- [V] `auth/_register.php` verifies a client-supplied `transaction_id` for "status = successful" only. It does not check amount, `tx_ref`, currency or reuse. Any successful payment could be replayed to register nominees. `tx_ref` and the ₦1,000 amount are set in browser JS.
- [?] Whether `_register.php` or `auth/register.php` + `verify_registration.php` is the live flow. `verify_registration` checks `tx_ref` against `registrations`; no amount check was seen in the lines read.

**Tickets**

- [V] One purchase creates one `tickets` row with one `ticket_code`. There is no quantity. `ticket_types.capacity` sets seats ("Table for N") and `checkins_count` tracks use.
- [V] `ticket_source` is `purchase` or `nominee`. Retrieval is by email + WhatsApp.
- [V] **Check-in race:** the scanner reads `checkins_count`, adds 1 in PHP and writes the value back. Two simultaneous scans can both write N+1.
- [V] `checkins_count` is `int(2)`. `ticket_code` and `reference` are UNIQUE.

**Authentication and authorization**

- [V] Passwords use `password_hash` / `password_verify`. There is no rate limiting, no `session_regenerate_id`, no CSRF on login, and the errors reveal whether an email exists. "Forgot password" is a dead link.
- [V] There is one `admins` table (id, username, password). There are no roles, permissions, editions or audit tables.
- [V] **No auth check:** `admin/delete-category.php` and `admin/edit-category.php`. Delete works via GET (`?id=`). `admin/.htaccess` was not read.
- [V] `admin/votes.php` needs an admin session plus a hardcoded developer key typed into a form.
- [V] All other admin pages read have a session check. They are only session-presence checks, not role checks.

**Secrets and repository hygiene (do not reproduce values)**

- [V] Flutterwave secret and/or webhook hash are hardcoded in 11 PHP files.
- [V] Two private SSL key files and certificates are in `/ssl`. `error_log` files are committed (one is 3,407 lines; contents not reviewed).
- [V] A default admin password is written in `password.php`.
- [V] `connect.php` holds local XAMPP-style defaults and prints the raw PDO error on failure. Production config is not in the repo.
- [V] `auth/mail.php` has SMTP credentials in source.

**Committee**

- [V] `committee_applications` workflow is pending → shortlisted → passed → approved or rejected. Screening date, location and notes are stored. Emails are sent on shortlist and approval. The admin page has CSRF and a session check.
- [V] There is no link between an approved applicant and an administrator account.

**Schema**

- [V] Tables: `admins`, `categories`, `miss_ogitech`, `nominees`, `registrations`, `settings`, `tickets`, `ticket_admins`, `ticket_types`, `votes`, `committee_applications`.
- [V] Foreign keys exist only on `nominees.category_id` (SET NULL), `votes.nominee_id` (CASCADE) and `tickets.ticket_type_id`. `votes.category_id` has no FK.
- [V] No table has `edition_id`. Results rank approved nominees by `votes DESC`.
- [V] Last commit: "Redirect retired OGA 9.0 public pages to OGA 10.0 landing page".

## 4. Verified: V2

- [V] All services are demo adapters on localStorage with "keep the signatures" comments. `http.js` has an `apiBase` switch for a real API.
- [V] `vote.service.confirmOrder` and `ticket.service.issueTickets` mark orders paid with no verification. `issueTickets` can be called twice.
- [V] `payment.service.checkout` is a simulated dialog. Nominee passwords are hashed in the browser and compared on the client.
- [V] `admin-auth.service.loginAdmin` takes an **email only** and checks no password.
- [V] Permissions: about 43 keys named `area.action`, each `read` or `write`, scope `edition` or `global`. Role templates: super, editor, treasurer, secretary, publicity, gate. Per edition, a person has `roleIds`, `grants` and `denies` (deny wins). `superAdmin` is a global flag.
- [V] Edition lifecycle: draft → active → completed → archived. Allowed transitions: draft→active, active→completed, completed→archived or active, archived→completed. Only one edition may be active. Only draft editions with no records can be deleted. New editions can copy settings, occasions, categories, ticket types and team.
- [V] **Gap:** `completed → active` reopening is allowed, which conflicts with "historical editions must not be modified".
- [V] No "last super-admin" guard found in `team.repo.js` (search only; not a full read).
- [V] V2 voting config has `maxVotesPerPayment: 50` and `maxPaidVotesPerEmailPerCategory: 5`. `vote.service` enforces only the first.
- [V] V2 `occasionId` is used as an alias of `editionId` in most records. `editions.json` also nests sub-events (Miss OGITECH) under `occasions[]` with their own ids.

## 5. Confirmed conflicts

| Topic           | Original                                                | V2                                            |
| --------------- | ------------------------------------------------------- | --------------------------------------------- |
| Per-email limit | 5 paid orders per email and category (≈250 votes)       | "5" with an ambiguous name; not enforced      |
| Ticket model    | 1 purchase = 1 ticket with N seats and `checkins_count` | order `qty` = N tickets, each `used: boolean` |
| Miss OGITECH    | Separate table, ₦10,000 hardcoded                       | An occasion inside an edition, disabled       |
| Admin model     | One `admins` table, no roles                            | 43 permissions, roles, edition-scoped access  |
| Edition         | Not modeled                                             | Modeled, client-side only                     |

## 6. Source-to-feature map

| Feature                     | Original source                                                              | V2 module needing a backend                              |
| --------------------------- | ---------------------------------------------------------------------------- | -------------------------------------------------------- |
| Vote purchase + confirm     | `process_vote`, `verify_payment`, `flutterwave_webhook`, `reconcile_pending` | `vote.service`, `payment.service`                        |
| Admin manual votes          | `admin/addvote`                                                              | admin `votes` page                                       |
| Nominee register + login    | `auth/*`                                                                     | `auth.service`, `register`, `login` pages                |
| Categories / nominees admin | `admin/categories`, `admin/*nominee*`                                        | `category.repo`, `nominee.repo`                          |
| Results                     | `results`, `admin/results`                                                   | `results` pages                                          |
| Tickets                     | `ticket/*`, `ticket_webhook`                                                 | `ticket.service`, admin `tickets`                        |
| Check-in                    | `ticket-admin/scanner`                                                       | admin `scanner` page                                     |
| Committee                   | `committee_apply`, `admin/committee`                                         | `committee-recruitment.service`, `invitation.repo`       |
| Roles, permissions, audit   | none                                                                         | `permissions.js`, `team.repo`, `admin-store`, audit page |
| Editions, occasions         | none                                                                         | `edition.repo`, `scope.js`, `editions.json`              |
| Admin auth                  | `admin/login`                                                                | `admin-auth.service`                                     |

## 7. Unresolved decisions

1. [?] Edition vs occasion. **[P]** Edition is the parent. Occasion is an optional sub-event inside an edition. The API uses `edition_id` everywhere, and `occasion_id` only for sub-events.
2. [?] Voting limit: 5 payments per email and category, or 5 votes? Is it per edition?
3. [?] Ticket model: N seats on one ticket (original), or N separate tickets per order (V2)?
4. [?] Should a completed edition be reopenable, and by whom?
5. [?] Is the registration fee ₦1,000 and is it configurable? Which register flow is live?
6. [?] How are historical OGA 9.0 records (and earlier) imported, and into which edition ids?

## 8. Remediation required regardless of the rebuild

1. Revoke exposed GitHub tokens.
2. Rotate the Flutterwave secret and webhook hash, SMTP password, database password and the developer key.
3. Remove the SSL keys, `error_log` files and secrets from git, and review history.
4. Replace the default admin password.
5. Add authentication to `delete-category.php` and `edit-category.php` on the live site now.
