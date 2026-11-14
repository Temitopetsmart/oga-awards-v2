-- Phase 2: ticket tiers (look), per-seat holder names, and ticket distribution (sellers, bulk batches, cash remittances).

ALTER TABLE ticket_types
  ADD COLUMN look               LONGTEXT NULL AFTER perks,                 -- tier wording and colours (JSON), set by admin
  ADD COLUMN allow_holder_names TINYINT(1) NOT NULL DEFAULT 1 AFTER look;  -- may buyers put a name on each seat?

ALTER TABLE ticket_orders
  ADD COLUMN channel     ENUM('online','seller','bulk') NOT NULL DEFAULT 'online' AFTER account_id,
  ADD COLUMN settlement  ENUM('paid','owe_later','free') NULL AFTER channel,      -- seller / bulk orders only
  ADD COLUMN unit_price  DECIMAL(12,2) NULL AFTER settlement,                      -- the price per unit the seller owes or paid
  ADD COLUMN issued_by   BIGINT UNSIGNED NULL AFTER unit_price,
  ADD COLUMN batch_label VARCHAR(100) NULL AFTER issued_by,
  ADD KEY ix_to_channel (edition_id, channel, issued_by),
  ADD CONSTRAINT fk_to_issued_by FOREIGN KEY (issued_by) REFERENCES people(id);

-- What each seller may take: how many, of which ticket, at what price, and how it is settled.
CREATE TABLE ticket_allocations (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id     BIGINT UNSIGNED NOT NULL,
  person_id      BIGINT UNSIGNED NOT NULL,
  ticket_type_id BIGINT UNSIGNED NOT NULL,
  settlement     ENUM('pay_now','owe_later','free') NOT NULL,
  unit_price     DECIMAL(12,2) NOT NULL DEFAULT 0,
  quota          INT UNSIGNED NOT NULL,
  active         TINYINT(1) NOT NULL DEFAULT 1,
  created_by     BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_alloc (person_id, ticket_type_id),
  KEY ix_alloc_edition (edition_id, person_id),
  CONSTRAINT fk_alloc_edition FOREIGN KEY (edition_id)     REFERENCES editions(id),
  CONSTRAINT fk_alloc_person  FOREIGN KEY (person_id)      REFERENCES people(id),
  CONSTRAINT fk_alloc_type    FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id),
  CONSTRAINT fk_alloc_by      FOREIGN KEY (created_by)     REFERENCES people(id)
) ENGINE=InnoDB;

-- Cash a seller handed in for tickets they were given "pay later".
CREATE TABLE ticket_remittances (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id  BIGINT UNSIGNED NOT NULL,
  person_id   BIGINT UNSIGNED NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  method      ENUM('cash','transfer','other') NOT NULL DEFAULT 'cash',
  note        VARCHAR(255) NULL,
  recorded_by BIGINT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rem_edition (edition_id, person_id),
  CONSTRAINT fk_rem_edition FOREIGN KEY (edition_id)  REFERENCES editions(id),
  CONSTRAINT fk_rem_person  FOREIGN KEY (person_id)   REFERENCES people(id),
  CONSTRAINT fk_rem_by      FOREIGN KEY (recorded_by) REFERENCES people(id)
) ENGINE=InnoDB;

INSERT INTO permissions (perm_key, area, label, kind, scope) VALUES
  ('tickets.distribute', 'Tickets', 'Sell or hand out tickets from my own allocation', 'write', 'edition'),
  ('tickets.allocations', 'Tickets', 'Set ticket allocations and record cash received', 'write', 'edition'),
  ('tickets.bulk', 'Tickets', 'Generate free bulk tickets', 'write', 'edition');

INSERT INTO roles (slug, name, description, is_system, is_locked) VALUES
  ('seller', 'Ticket seller', 'Sells or hands out tickets from their own allocation.', 1, 0);

INSERT INTO role_permissions (role_id, perm_key)
  SELECT r.id, p.perm_key
    FROM roles r
    JOIN (SELECT 'dashboard.view' AS perm_key UNION ALL SELECT 'tickets.distribute') p
   WHERE r.slug = 'seller';

INSERT INTO role_permissions (role_id, perm_key)
  SELECT r.id, 'tickets.allocations' FROM roles r WHERE r.slug IN ('ticketing', 'treasurer');