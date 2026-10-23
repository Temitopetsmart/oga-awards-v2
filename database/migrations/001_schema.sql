-- OGA Awards V2 — schema v1
-- Needs MySQL 8.0+ or MariaDB 10.5+ (generated columns, CHECK constraints, JSON).
-- Rule: nothing cascades on delete. History is never destroyed by deleting a parent row.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============ EDITIONS ============
CREATE TABLE editions (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug             VARCHAR(40)  NOT NULL,                 -- 'oga-10'
  name             VARCHAR(100) NOT NULL,                 -- 'OGA'
  edition_label    VARCHAR(40)  NOT NULL,                 -- '10.0'
  year             SMALLINT UNSIGNED NOT NULL,
  tagline          VARCHAR(255) NULL,
  status           ENUM('draft','active','completed','archived') NOT NULL DEFAULT 'draft',
  state_mode       ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  force_state      VARCHAR(30) NULL,
  nominations_open_at   DATETIME NULL,
  nominations_close_at  DATETIME NULL,
  voting_open_at        DATETIME NULL,
  voting_close_at       DATETIME NULL,
  results_publish_at    DATETIME NULL,
  ceremony_at           DATETIME NULL,
  features         JSON NULL,                             -- {"tickets":true,"committeeRecruitment":true}
  -- Trick: only 'active' rows get 1, others NULL, so UNIQUE allows only ONE active edition.
  active_flag      TINYINT GENERATED ALWAYS AS (IF(status = 'active', 1, NULL)) STORED,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_edition_slug (slug),
  UNIQUE KEY uq_edition_active (active_flag)
) ENGINE=InnoDB;

-- Admin-controlled money and limits (nothing hardcoded in PHP).
CREATE TABLE edition_settings (
  edition_id                    BIGINT UNSIGNED PRIMARY KEY,
  currency                      CHAR(3) NOT NULL DEFAULT 'NGN',
  price_per_vote                DECIMAL(12,2) NOT NULL DEFAULT 100.00,
  max_votes_per_payment         INT UNSIGNED NULL,        -- NULL = no limit
  max_paid_payments_per_email_per_category INT UNSIGNED NULL,  -- NULL = no limit
  nominee_registration_fee      DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- 0 = free
  updated_by                    BIGINT UNSIGNED NULL,
  updated_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_es_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CHECK (price_per_vote > 0),
  CHECK (nominee_registration_fee >= 0)
) ENGINE=InnoDB;

-- Sub-events inside an edition (e.g. Miss OGITECH).
CREATE TABLE occasions (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id   BIGINT UNSIGNED NOT NULL,
  slug         VARCHAR(60) NOT NULL,
  type         VARCHAR(40) NOT NULL,
  name         VARCHAR(150) NOT NULL,
  tagline      VARCHAR(255) NULL,
  icon         VARCHAR(60) NULL,
  enabled      TINYINT(1) NOT NULL DEFAULT 0,
  link_href    VARCHAR(255) NULL,
  description  TEXT NULL,
  actions      JSON NULL,
  UNIQUE KEY uq_occ (edition_id, slug),
  CONSTRAINT fk_occ_edition FOREIGN KEY (edition_id) REFERENCES editions(id)
) ENGINE=InnoDB;

-- ============ PEOPLE, ROLES, ACCESS ============
CREATE TABLE people (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(150) NOT NULL,
  email          VARCHAR(190) NULL,                       -- NULL = committee member with no login
  password_hash  VARCHAR(255) NULL,                       -- NULL until they accept an invite
  active         TINYINT(1) NOT NULL DEFAULT 1,
  is_super_admin TINYINT(1) NOT NULL DEFAULT 0,
  photo_path     VARCHAR(255) NULL,
  last_login_at  DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_people_email (email)
) ENGINE=InnoDB;

CREATE TABLE permissions (
  perm_key  VARCHAR(60) PRIMARY KEY,                      -- 'votes.void'
  area      VARCHAR(40) NOT NULL,
  label     VARCHAR(150) NOT NULL,
  kind      ENUM('read','write') NOT NULL,
  scope     ENUM('edition','global') NOT NULL DEFAULT 'edition'
) ENGINE=InnoDB;

CREATE TABLE roles (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(60) NOT NULL,
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_system   TINYINT(1) NOT NULL DEFAULT 0,
  is_locked   TINYINT(1) NOT NULL DEFAULT 0,              -- locked = Super role, not editable
  UNIQUE KEY uq_role_slug (slug)
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id   BIGINT UNSIGNED NOT NULL,
  perm_key  VARCHAR(60) NOT NULL,
  PRIMARY KEY (role_id, perm_key),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id),
  CONSTRAINT fk_rp_perm FOREIGN KEY (perm_key) REFERENCES permissions(perm_key)
) ENGINE=InnoDB;

CREATE TABLE committee_units (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug        VARCHAR(60) NOT NULL,
  name        VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  icon        VARCHAR(60) NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_unit_slug (slug)
) ENGINE=InnoDB;

-- A person's position and admin access, per edition. Being on the committee gives NO admin access
-- by itself: access comes only from rows in assignment_roles / assignment_overrides.
CREATE TABLE edition_assignments (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  person_id   BIGINT UNSIGNED NOT NULL,
  edition_id  BIGINT UNSIGNED NOT NULL,
  position    VARCHAR(100) NOT NULL DEFAULT '',
  unit_id     BIGINT UNSIGNED NULL,
  visible     TINYINT(1) NOT NULL DEFAULT 0,              -- show on public committee page
  UNIQUE KEY uq_assign (person_id, edition_id),
  CONSTRAINT fk_ea_person  FOREIGN KEY (person_id)  REFERENCES people(id),
  CONSTRAINT fk_ea_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_ea_unit    FOREIGN KEY (unit_id)    REFERENCES committee_units(id)
) ENGINE=InnoDB;

CREATE TABLE assignment_roles (
  assignment_id BIGINT UNSIGNED NOT NULL,
  role_id       BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (assignment_id, role_id),
  CONSTRAINT fk_ar_a FOREIGN KEY (assignment_id) REFERENCES edition_assignments(id),
  CONSTRAINT fk_ar_r FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- Extra grants and blocks on top of roles. A 'deny' always wins.
CREATE TABLE assignment_overrides (
  assignment_id BIGINT UNSIGNED NOT NULL,
  perm_key      VARCHAR(60) NOT NULL,
  effect        ENUM('grant','deny') NOT NULL,
  PRIMARY KEY (assignment_id, perm_key),
  CONSTRAINT fk_ao_a FOREIGN KEY (assignment_id) REFERENCES edition_assignments(id),
  CONSTRAINT fk_ao_p FOREIGN KEY (perm_key) REFERENCES permissions(perm_key)
) ENGINE=InnoDB;

CREATE TABLE invitations (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_hash   CHAR(64) NOT NULL,                         -- SHA-256 of the emailed token
  email        VARCHAR(190) NOT NULL,
  edition_id   BIGINT UNSIGNED NOT NULL,
  invited_by   BIGINT UNSIGNED NULL,
  status       ENUM('pending','accepted','revoked','expired') NOT NULL DEFAULT 'pending',
  expires_at   DATETIME NOT NULL,
  accepted_at  DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_token (token_hash),
  KEY ix_inv_email (email, edition_id, status),
  CONSTRAINT fk_inv_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_inv_by FOREIGN KEY (invited_by) REFERENCES people(id)
) ENGINE=InnoDB;

CREATE TABLE invitation_roles (
  invitation_id BIGINT UNSIGNED NOT NULL,
  role_id       BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (invitation_id, role_id),
  CONSTRAINT fk_ir_i FOREIGN KEY (invitation_id) REFERENCES invitations(id),
  CONSTRAINT fk_ir_r FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- ============ NOMINEES ============
CREATE TABLE categories (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id  BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(150) NOT NULL,
  description VARCHAR(500) NULL,
  icon        VARCHAR(60) NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cat_name (edition_id, name),
  CONSTRAINT fk_cat_edition FOREIGN KEY (edition_id) REFERENCES editions(id)
) ENGINE=InnoDB;

-- Nominee login account (one per email, reused across editions).
CREATE TABLE accounts (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email             VARCHAR(190) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,                -- password_hash() on the server (argon2id/bcrypt)
  email_verified_at DATETIME NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_acc_email (email)
) ENGINE=InnoDB;

-- Reset-password and verify-email tokens (hashed, single use, expiring).
CREATE TABLE account_tokens (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id  BIGINT UNSIGNED NOT NULL,
  type        ENUM('password_reset','email_verify') NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  used_at     DATETIME NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_at_hash (token_hash),
  CONSTRAINT fk_at_acc FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

-- One entry per account per edition. Admin-created nominees have account_id NULL.
CREATE TABLE nominee_entries (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id     BIGINT UNSIGNED NOT NULL,
  account_id     BIGINT UNSIGNED NULL,
  category_id    BIGINT UNSIGNED NOT NULL,
  name           VARCHAR(150) NOT NULL,
  phone          VARCHAR(30) NULL,
  department     VARCHAR(150) NULL,
  level          VARCHAR(30) NULL,
  bio            TEXT NULL,
  photo_path     VARCHAR(255) NULL,
  status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  registration_payment_id BIGINT UNSIGNED NULL,           -- set only when a fee applied
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_entry_account_edition (account_id, edition_id),
  KEY ix_entry_edition_cat (edition_id, category_id, status),
  CONSTRAINT fk_ne_edition FOREIGN KEY (edition_id)  REFERENCES editions(id),
  CONSTRAINT fk_ne_account FOREIGN KEY (account_id)  REFERENCES accounts(id),
  CONSTRAINT fk_ne_cat     FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

-- ============ PAYMENTS (shared by votes, tickets, registration fees) ============
CREATE TABLE payments (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id              BIGINT UNSIGNED NOT NULL,
  purpose                 ENUM('vote','ticket','nominee_fee') NOT NULL,
  reference               VARCHAR(80) NOT NULL,           -- our tx_ref, generated on the server
  provider                VARCHAR(30) NOT NULL DEFAULT 'flutterwave',
  provider_transaction_id VARCHAR(80) NULL,               -- set once verified
  expected_amount         DECIMAL(12,2) NOT NULL,         -- what WE computed, never from the browser
  paid_amount             DECIMAL(12,2) NULL,
  currency                CHAR(3) NOT NULL DEFAULT 'NGN',
  payer_email             VARCHAR(190) NOT NULL,
  status                  ENUM('pending','successful','failed','abandoned') NOT NULL DEFAULT 'pending',
  verified_at             DATETIME NULL,
  provider_payload        JSON NULL,
  created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pay_ref (reference),
  UNIQUE KEY uq_pay_provider_tx (provider, provider_transaction_id),   -- one provider payment, one record
  KEY ix_pay_status (status, created_at),
  CONSTRAINT fk_pay_edition FOREIGN KEY (edition_id) REFERENCES editions(id)
) ENGINE=InnoDB;

ALTER TABLE nominee_entries
  ADD CONSTRAINT fk_ne_pay FOREIGN KEY (registration_payment_id) REFERENCES payments(id);

-- Every webhook delivery is logged once; repeats are ignored.
CREATE TABLE webhook_events (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider      VARCHAR(30) NOT NULL,
  event_key     VARCHAR(120) NOT NULL,
  payload       JSON NOT NULL,
  received_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at  DATETIME NULL,
  UNIQUE KEY uq_wh (provider, event_key)
) ENGINE=InnoDB;

-- ============ VOTES ============
-- Totals are always SUM(votes) of status='paid'. There is no stored counter to drift.
CREATE TABLE vote_orders (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id       BIGINT UNSIGNED NOT NULL,
  reference        VARCHAR(80) NOT NULL,
  nominee_entry_id BIGINT UNSIGNED NOT NULL,
  category_id      BIGINT UNSIGNED NOT NULL,
  votes            INT UNSIGNED NOT NULL,
  amount           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  voter_name       VARCHAR(150) NOT NULL,
  voter_email      VARCHAR(190) NOT NULL,
  status           ENUM('pending','paid','void') NOT NULL DEFAULT 'pending',
  source           ENUM('payment','manual') NOT NULL DEFAULT 'payment',
  payment_id       BIGINT UNSIGNED NULL,
  reason           VARCHAR(500) NULL,                      -- required for manual votes
  created_by       BIGINT UNSIGNED NULL,                   -- admin who added a manual order
  paid_at          DATETIME NULL,
  voided_by        BIGINT UNSIGNED NULL,
  voided_at        DATETIME NULL,
  void_reason      VARCHAR(500) NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vo_ref (reference),
  UNIQUE KEY uq_vo_payment (payment_id),                   -- one payment can credit one order only
  KEY ix_vo_tally (edition_id, nominee_entry_id, status),
  KEY ix_vo_limit (edition_id, category_id, voter_email, status),
  CONSTRAINT fk_vo_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_vo_nominee FOREIGN KEY (nominee_entry_id) REFERENCES nominee_entries(id),
  CONSTRAINT fk_vo_cat     FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_vo_pay     FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_vo_by      FOREIGN KEY (created_by) REFERENCES people(id),
  CONSTRAINT fk_vo_voidby  FOREIGN KEY (voided_by) REFERENCES people(id),
  CHECK (votes > 0),
  CHECK (source = 'payment' OR reason IS NOT NULL)
) ENGINE=InnoDB;

-- Lock row used to enforce the per-email limit safely under concurrent requests.
CREATE TABLE vote_limit_counters (
  edition_id    BIGINT UNSIGNED NOT NULL,
  category_id   BIGINT UNSIGNED NOT NULL,
  voter_email   VARCHAR(190) NOT NULL,
  paid_orders   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (edition_id, category_id, voter_email),
  CONSTRAINT fk_vlc_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_vlc_cat FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

CREATE TABLE winners (
  edition_id       BIGINT UNSIGNED NOT NULL,
  category_id      BIGINT UNSIGNED NOT NULL,
  nominee_entry_id BIGINT UNSIGNED NOT NULL,
  set_by           BIGINT UNSIGNED NULL,
  set_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (edition_id, category_id),
  CONSTRAINT fk_w_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_w_cat FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_w_nominee FOREIGN KEY (nominee_entry_id) REFERENCES nominee_entries(id),
  CONSTRAINT fk_w_by FOREIGN KEY (set_by) REFERENCES people(id)
) ENGINE=InnoDB;

-- ============ TICKETS ============
CREATE TABLE ticket_types (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id    BIGINT UNSIGNED NOT NULL,
  occasion_id   BIGINT UNSIGNED NULL,
  name          VARCHAR(100) NOT NULL,
  price         DECIMAL(12,2) NOT NULL,                   -- price of ONE unit (one seat, or one table)
  seats         TINYINT UNSIGNED NOT NULL DEFAULT 1,      -- 1 = single seat; 4 = table of 4
  description   VARCHAR(500) NULL,
  perks         JSON NULL,
  max_per_order INT UNSIGNED NOT NULL DEFAULT 10,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_tt_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_tt_occ FOREIGN KEY (occasion_id) REFERENCES occasions(id),
  CHECK (seats >= 1),
  CHECK (price >= 0)
) ENGINE=InnoDB;

CREATE TABLE ticket_orders (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id    BIGINT UNSIGNED NOT NULL,
  reference     VARCHAR(80) NOT NULL,
  ticket_type_id BIGINT UNSIGNED NOT NULL,
  qty           INT UNSIGNED NOT NULL,                    -- units bought (tables or single seats)
  amount        DECIMAL(12,2) NOT NULL,
  buyer_name    VARCHAR(150) NOT NULL,
  buyer_email   VARCHAR(190) NOT NULL,
  buyer_phone   VARCHAR(30) NOT NULL,
  status        ENUM('pending','paid','failed','void') NOT NULL DEFAULT 'pending',
  payment_id    BIGINT UNSIGNED NULL,
  paid_at       DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_to_ref (reference),
  UNIQUE KEY uq_to_payment (payment_id),
  KEY ix_to_buyer (buyer_email),
  CONSTRAINT fk_to_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_to_type FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id),
  CONSTRAINT fk_to_pay FOREIGN KEY (payment_id) REFERENCES payments(id),
  CHECK (qty > 0)
) ENGINE=InnoDB;

-- One group per unit bought. A table of 4 = 1 group + 4 tickets that share group_code.
-- Scanning any seat shows the group, so staff know who came together and where to seat them.
CREATE TABLE ticket_groups (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id  BIGINT UNSIGNED NOT NULL,
  order_id    BIGINT UNSIGNED NOT NULL,
  group_code  VARCHAR(20) NOT NULL,
  seats       TINYINT UNSIGNED NOT NULL,
  table_label VARCHAR(60) NULL,                           -- e.g. 'Table 7 — VIP', set by admin
  UNIQUE KEY uq_tg_code (group_code),
  CONSTRAINT fk_tg_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_tg_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id)
) ENGINE=InnoDB;

CREATE TABLE tickets (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id     BIGINT UNSIGNED NOT NULL,
  order_id       BIGINT UNSIGNED NOT NULL,
  group_id       BIGINT UNSIGNED NOT NULL,
  ticket_code    VARCHAR(24) NOT NULL,                    -- unique per seat; this is what the QR holds
  seat_no        TINYINT UNSIGNED NOT NULL,
  holder_name    VARCHAR(150) NOT NULL,
  status         ENUM('issued','void') NOT NULL DEFAULT 'issued',
  checked_in_at  DATETIME NULL,
  checked_in_by  BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ticket_code (ticket_code),
  UNIQUE KEY uq_ticket_seat (group_id, seat_no),          -- cannot issue the same seat twice
  KEY ix_ticket_edition (edition_id, checked_in_at),
  CONSTRAINT fk_t_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_t_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id),
  CONSTRAINT fk_t_group FOREIGN KEY (group_id) REFERENCES ticket_groups(id),
  CONSTRAINT fk_t_by FOREIGN KEY (checked_in_by) REFERENCES people(id)
) ENGINE=InnoDB;
-- Atomic check-in (one scan wins even if two phones scan at once):
--   UPDATE tickets SET checked_in_at = NOW(), checked_in_by = ?
--   WHERE id = ? AND status = 'issued' AND checked_in_at IS NULL;   -- rowCount = 1 means valid

CREATE TABLE ticket_scans (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id   BIGINT UNSIGNED NULL,
  code_scanned VARCHAR(60) NOT NULL,
  ticket_id    BIGINT UNSIGNED NULL,
  result       ENUM('valid','used','invalid','void','wrong_edition') NOT NULL,
  scanned_by   BIGINT UNSIGNED NULL,
  scanned_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_scan_edition (edition_id, scanned_at),
  CONSTRAINT fk_sc_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id),
  CONSTRAINT fk_sc_by FOREIGN KEY (scanned_by) REFERENCES people(id)
) ENGINE=InnoDB;

-- ============ COMMITTEE RECRUITMENT ============
CREATE TABLE committee_config (
  edition_id             BIGINT UNSIGNED PRIMARY KEY,
  enabled                TINYINT(1) NOT NULL DEFAULT 0,
  opens_at               DATETIME NULL,
  closes_at              DATETIME NULL,
  target_applicants      INT UNSIGNED NOT NULL DEFAULT 100,
  screening_date         DATE NULL,
  screening_time         VARCHAR(20) NULL,
  screening_venue        VARCHAR(255) NULL,
  screening_instructions TEXT NULL,
  approval_message       TEXT NULL,
  unsuccessful_message   TEXT NULL,
  reserve_message        TEXT NULL,
  onboarding_message     TEXT NULL,
  whatsapp_group_url     VARCHAR(255) NULL,
  photo_uploads_enabled  TINYINT(1) NOT NULL DEFAULT 1,
  photo_max_kb           INT UNSIGNED NOT NULL DEFAULT 1024,
  allowed_photo_types    JSON NULL,
  CONSTRAINT fk_cc_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CHECK (photo_max_kb BETWEEN 50 AND 10240)
) ENGINE=InnoDB;

CREATE TABLE committee_applications (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id    BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(150) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(30) NOT NULL,
  department    VARCHAR(150) NOT NULL,
  level         VARCHAR(30) NOT NULL,
  unit_id       BIGINT UNSIGNED NOT NULL,
  motivation    TEXT NOT NULL,
  photo_path    VARCHAR(255) NULL,
  status        ENUM('submitted','shortlisted','screened','approved','reserve',
                     'unsuccessful','reconsideration','appointed','withdrawn')
                NOT NULL DEFAULT 'submitted',
  screening        JSON NULL,
  screening_result JSON NULL,
  decision         VARCHAR(60) NULL,
  decision_reason  TEXT NULL,
  -- one live application per email per edition; a withdrawn one frees the email to re-apply
  live_email    VARCHAR(190) GENERATED ALWAYS AS (IF(status = 'withdrawn', NULL, email)) STORED,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ca_live (edition_id, live_email),
  KEY ix_ca_status (edition_id, status),
  CONSTRAINT fk_ca_edition FOREIGN KEY (edition_id) REFERENCES editions(id),
  CONSTRAINT fk_ca_unit FOREIGN KEY (unit_id) REFERENCES committee_units(id)
) ENGINE=InnoDB;

CREATE TABLE committee_application_history (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  application_id BIGINT UNSIGNED NOT NULL,
  stage          VARCHAR(30) NOT NULL,
  actor_id       BIGINT UNSIGNED NULL,
  note           VARCHAR(1000) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cah_app (application_id, created_at),
  CONSTRAINT fk_cah_app FOREIGN KEY (application_id) REFERENCES committee_applications(id),
  CONSTRAINT fk_cah_actor FOREIGN KEY (actor_id) REFERENCES people(id)
) ENGINE=InnoDB;

-- ============ MESSAGES, SETTINGS, NOTIFICATIONS, AUDIT ============
CREATE TABLE contact_messages (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id  BIGINT UNSIGNED NULL,
  name        VARCHAR(150) NOT NULL,
  email       VARCHAR(190) NOT NULL,
  subject     VARCHAR(200) NOT NULL,
  message     TEXT NOT NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cm_read (is_read, created_at),
  CONSTRAINT fk_cm_edition FOREIGN KEY (edition_id) REFERENCES editions(id)
) ENGINE=InnoDB;

-- Site-wide, non-secret settings (contact details, social links, email templates, gateway mode).
-- Secrets (Flutterwave secret key, webhook hash, SMTP password) live ONLY in server env variables.
CREATE TABLE site_settings (
  setting_key  VARCHAR(80) PRIMARY KEY,
  value        JSON NOT NULL,
  updated_by   BIGINT UNSIGNED NULL,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE notification_outbox (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id  BIGINT UNSIGNED NULL,
  type        VARCHAR(60) NOT NULL,
  recipient   VARCHAR(190) NOT NULL,
  payload     JSON NULL,
  status      ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error  VARCHAR(500) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at     DATETIME NULL,
  KEY ix_no_status (status, created_at)
) ENGINE=InnoDB;

-- Append-only. The app database user should have INSERT and SELECT only on this table.
CREATE TABLE audit_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id  BIGINT UNSIGNED NULL,
  person_id   BIGINT UNSIGNED NULL,
  action      VARCHAR(150) NOT NULL,
  target      VARCHAR(255) NULL,
  details     JSON NULL,
  ip_address  VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_audit_edition (edition_id, created_at),
  KEY ix_audit_person (person_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  identifier  CHAR(64) NOT NULL,                          -- SHA-256 of lower-cased email
  ip_address  VARCHAR(45) NOT NULL,
  success     TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_la_id (identifier, attempted_at),
  KEY ix_la_ip (ip_address, attempted_at)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;