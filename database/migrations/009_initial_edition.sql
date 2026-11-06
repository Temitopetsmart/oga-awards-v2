-- Phase 3: a real site starts EMPTY. One OGA 10.0 edition, ready to be configured in the admin
-- (Settings: dates, prices and limits; Categories; Ticket types ...), and the committee structure.
-- Safe to run on a database that already has editions: it then does nothing.

INSERT INTO editions (slug, name, edition_label, year, tagline, status, state_mode, features)
SELECT 'oga-10', 'OGA', '10.0', 2027, 'The OGITECH Student Awards', 'active', 'auto',
       '{"tickets": false, "committeeRecruitment": false, "showVoteCounts": false}'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM editions);

INSERT INTO edition_settings (edition_id, price_per_vote, max_votes_per_payment, max_paid_payments_per_email_per_category)
SELECT e.id, 100, 50, 5
FROM editions e
WHERE e.slug = 'oga-10'
  AND NOT EXISTS (SELECT 1 FROM edition_settings s WHERE s.edition_id = e.id);

INSERT INTO committee_units (slug, name, description, icon, sort_order)
SELECT u.slug, u.name, u.description, u.icon, u.sort_order
FROM (
    SELECT 'u-exec' AS slug, 'Executive' AS name, 'Leads strategy and keeps everything on track.' AS description, 'fa-landmark' AS icon, 1 AS sort_order
    UNION ALL SELECT 'u-media', 'Media & Publicity', 'Content, design and communications.', 'fa-bullhorn', 2
    UNION ALL SELECT 'u-tech', 'Technology', 'Website, voting and ticketing.', 'fa-laptop-code', 3
    UNION ALL SELECT 'u-logistics', 'Logistics & Events', 'Venue, protocol and ceremony flow.', 'fa-clipboard-list', 4
) u
WHERE NOT EXISTS (SELECT 1 FROM committee_units);