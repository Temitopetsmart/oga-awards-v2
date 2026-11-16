-- Phase 4: advertising. Businesses pay to advertise; admins review; approved ads rotate on the public site and in the Nominee Portal.
-- Nothing here is permanent: every ad has an end date. Prices, durations, placements and wording are all admin-controlled.

ALTER TABLE payments
  MODIFY purpose ENUM('vote','ticket','nominee_fee','ad') NOT NULL;

-- What an advertiser can buy. The admin decides price, length, where it shows, and what must be uploaded.
CREATE TABLE ad_categories (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug          VARCHAR(60)  NOT NULL,
  name          VARCHAR(80)  NOT NULL,
  description   VARCHAR(500) NOT NULL DEFAULT '',
  price         DECIMAL(12,2) NOT NULL DEFAULT 0,
  duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  placements    LONGTEXT NOT NULL,                               -- JSON list of placement keys, for example ["site_footer","portal"]
  media_mode    ENUM('text','image','image_video') NOT NULL DEFAULT 'image',
  require_logo   TINYINT(1) NOT NULL DEFAULT 0,
  require_banner TINYINT(1) NOT NULL DEFAULT 0,
  max_image_kb  INT UNSIGNED NOT NULL DEFAULT 2048,
  max_headline  SMALLINT UNSIGNED NOT NULL DEFAULT 70,
  max_text      SMALLINT UNSIGNED NOT NULL DEFAULT 160,
  max_running   SMALLINT UNSIGNED NOT NULL DEFAULT 0,            -- 0 = no limit on how many of this category run at once
  active        TINYINT(1) NOT NULL DEFAULT 1,
  sort_order    INT NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_adcat_slug (slug)
) ENGINE=InnoDB;

CREATE TABLE ads (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference     VARCHAR(30) NOT NULL,
  category_id   BIGINT UNSIGNED NOT NULL,
  category_name VARCHAR(80) NOT NULL,                            -- what it was called when bought
  status        ENUM('draft','awaiting_payment','paid','approved','expired','rejected','removed') NOT NULL DEFAULT 'draft',
  source        ENUM('advertiser','admin') NOT NULL DEFAULT 'advertiser',
  paid_via      ENUM('online','manual','free') NULL,
  business_name VARCHAR(120) NOT NULL,
  contact_name  VARCHAR(100) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(30)  NOT NULL DEFAULT '',
  headline      VARCHAR(120) NOT NULL,
  body          VARCHAR(500) NOT NULL DEFAULT '',
  link_url      VARCHAR(500) NOT NULL,
  cta_label     VARCHAR(40)  NOT NULL DEFAULT '',
  video_url     VARCHAR(255) NULL,
  logo_path     VARCHAR(255) NULL,
  banner_path   VARCHAR(255) NULL,
  placements    LONGTEXT NOT NULL,                               -- JSON list; copied from the category, an admin may change it
  duration_days SMALLINT UNSIGNED NOT NULL,
  amount        DECIMAL(12,2) NOT NULL DEFAULT 0,                -- what it cost when bought
  payment_id    BIGINT UNSIGNED NULL,
  paid_at       DATETIME NULL,
  paid_note     VARCHAR(255) NULL,
  marked_paid_by BIGINT UNSIGNED NULL,
  starts_at     DATETIME NULL,
  ends_at       DATETIME NULL,
  review_note   VARCHAR(500) NULL,
  reviewed_by   BIGINT UNSIGNED NULL,
  reviewed_at   DATETIME NULL,
  renewal_of    BIGINT UNSIGNED NULL,
  refunded_at   DATETIME NULL,                                   -- set when money was handed back after a rejection or removal
  refund_note   VARCHAR(255) NULL,
  expiring_notified TINYINT(1) NOT NULL DEFAULT 0,
  impressions   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  clicks        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_by    BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ads_ref (reference),
  KEY ix_ads_status (status, ends_at),
  KEY ix_ads_email (email),
  KEY ix_ads_payment (payment_id),
  KEY ix_ads_renewal (renewal_of),
  CONSTRAINT fk_ads_category FOREIGN KEY (category_id)    REFERENCES ad_categories(id),
  CONSTRAINT fk_ads_payment  FOREIGN KEY (payment_id)     REFERENCES payments(id),
  CONSTRAINT fk_ads_paidby   FOREIGN KEY (marked_paid_by) REFERENCES people(id),
  CONSTRAINT fk_ads_reviewer FOREIGN KEY (reviewed_by)    REFERENCES people(id),
  CONSTRAINT fk_ads_creator  FOREIGN KEY (created_by)     REFERENCES people(id)
) ENGINE=InnoDB;

-- Aggregate counters only: how many times an ad was shown and clicked, per day and place. No visitor data of any kind.
CREATE TABLE ad_stats (
  ad_id       BIGINT UNSIGNED NOT NULL,
  day         DATE NOT NULL,
  placement   VARCHAR(40) NOT NULL,
  impressions INT UNSIGNED NOT NULL DEFAULT 0,
  clicks      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (ad_id, day, placement),
  CONSTRAINT fk_adstat_ad FOREIGN KEY (ad_id) REFERENCES ads(id)
) ENGINE=InnoDB;

-- Starting settings. Everything here is edited in Admin, Ad setup. Submissions start CLOSED and the starter categories start OFF,
-- so nothing can be bought until an admin has set real prices.
INSERT INTO site_settings (setting_key, value) VALUES ('ads', JSON_OBJECT(
  'enabled', TRUE,
  'submissionsOpen', FALSE,
  'label', 'Ad',
  'defaultCta', 'Learn more',
  'intro', 'Reach OGITECH students and the whole awards audience. Choose a package, upload your details, and pay securely online. Every ad is reviewed before it goes live.',
  'terms', 'Ads must be honest, legal and suitable for a student audience. We review every ad and may reject or remove one at any time. Each ad runs for the period of the package you choose, then stops.',
  'expiryWarningDays', 3,
  'maxDurationDays', 365,
  'maxPendingPerEmail', 3,
  'placements', JSON_OBJECT(
    'site_footer', JSON_OBJECT('label', 'Every public page (above the footer)', 'enabled', TRUE, 'rotateSeconds', 8, 'maxAds', 6),
    'home_banner', JSON_OBJECT('label', 'Home page banner',                       'enabled', TRUE, 'rotateSeconds', 8, 'maxAds', 6),
    'portal',      JSON_OBJECT('label', 'Nominee Portal (all pages)',            'enabled', TRUE, 'rotateSeconds', 10, 'maxAds', 6)
  )
));

INSERT INTO ad_categories (slug, name, description, price, duration_days, placements, media_mode, require_logo, require_banner, active, sort_order) VALUES
  ('mention',  'Mention',  'A short text mention with a link.',                     0, 14, '["site_footer"]',                         'text',        0, 0, 0, 1),
  ('standard', 'Standard', 'Logo, banner and a link, shown across the site.',      0, 30, '["site_footer","portal"]',                'image',       1, 1, 0, 2),
  ('sponsor',  'Sponsor',  'Logo, banner, a video link and the home page banner.',  0, 60, '["site_footer","home_banner","portal"]',  'image_video', 1, 1, 0, 3);

INSERT INTO permissions (perm_key, area, label, kind, scope) VALUES
  ('ads.view',   'Advertising', 'View ads, reports and ad revenue',                      'read',  'global'),
  ('ads.review', 'Advertising', 'Approve, reject and correct ads',                       'write', 'global'),
  ('ads.manage', 'Advertising', 'Set up ad packages, add free ads, record cash, remove', 'write', 'global');

INSERT INTO roles (slug, name, description, is_system, is_locked) VALUES
  ('advertising', 'Advertising manager', 'Reviews and manages ads, packages and ad reports.', 1, 0);

INSERT INTO role_permissions (role_id, perm_key)
  SELECT r.id, p.perm_key
    FROM roles r
    JOIN (SELECT 'dashboard.view' AS perm_key UNION ALL SELECT 'ads.view' UNION ALL SELECT 'ads.review' UNION ALL SELECT 'ads.manage') p
   WHERE r.slug = 'advertising';

INSERT INTO role_permissions (role_id, perm_key)
  SELECT r.id, 'ads.view' FROM roles r WHERE r.slug IN ('treasurer', 'auditor');