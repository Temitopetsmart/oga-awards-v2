-- Phase 5 + 6: speed. Indexes chosen by measuring the real queries on 300,000 vote orders, 30,000 ticket orders
-- and 200,000 audit rows (see docs/backend/performance.md), and precomputed vote totals.

-- Admin lists: "newest first" pages and their status/category filters read the first rows of an index
-- instead of sorting the whole edition.
ALTER TABLE vote_orders
    ADD KEY ix_vo_ed_id (edition_id, id),
    ADD KEY ix_vo_ed_status_id (edition_id, status, id),
    ADD KEY ix_vo_ed_cat_status_id (edition_id, category_id, status, id);

ALTER TABLE ticket_orders
    ADD KEY ix_to_ed_status_id (edition_id, status, id),
    ADD KEY ix_to_ed_id (edition_id, id);
ALTER TABLE tickets
    ADD KEY ix_ticket_ed_id (edition_id, id),
    ADD KEY ix_ticket_ed_used (edition_id, checked_in_at, id);
ALTER TABLE audit_log ADD KEY ix_audit_ed_id (edition_id, id);
ALTER TABLE contact_messages ADD KEY ix_cm_ed_read_id (edition_id, is_read, id);
ALTER TABLE notification_outbox ADD KEY ix_no_status_id (status, id);

-- Precomputed vote totals: one small row per nominee and category. Results and the dashboard read these
-- instead of adding up every vote order. They are updated in the same database transaction that credits,
-- adds or voids votes (see VoteTotals.php) and can be checked or rebuilt with backend/tools/rebuild-vote-totals.php.
CREATE TABLE vote_totals (
    nominee_entry_id BIGINT UNSIGNED NOT NULL,
    category_id      BIGINT UNSIGNED NOT NULL,
    edition_id       BIGINT UNSIGNED NOT NULL,
    votes            BIGINT NOT NULL DEFAULT 0,          -- all paid votes (online and manual)
    manual_votes     BIGINT NOT NULL DEFAULT 0,          -- of which added by an admin
    amount           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (nominee_entry_id, category_id),
    KEY ix_vt_edition (edition_id, category_id),
    CONSTRAINT fk_vt_nominee FOREIGN KEY (nominee_entry_id) REFERENCES nominee_entries (id),
    CONSTRAINT fk_vt_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT fk_vt_edition FOREIGN KEY (edition_id) REFERENCES editions (id)
) ENGINE=InnoDB;

INSERT INTO vote_totals (nominee_entry_id, category_id, edition_id, votes, manual_votes, amount)
SELECT nominee_entry_id, category_id, edition_id, SUM(votes), SUM(CASE WHEN source = 'manual' THEN votes ELSE 0 END), SUM(amount)
FROM vote_orders
WHERE status = 'paid'
GROUP BY nominee_entry_id, category_id, edition_id;