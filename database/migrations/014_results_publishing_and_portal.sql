-- Phase 10: results are published on purpose, not by accident.
ALTER TABLE editions
    ADD COLUMN results_published_at DATETIME NULL AFTER results_publish_at,
    ADD COLUMN results_published_by BIGINT UNSIGNED NULL AFTER results_published_at,
    ADD CONSTRAINT fk_ed_results_by FOREIGN KEY (results_published_by) REFERENCES people(id);

-- Why the admin chose a winner who is not the vote leader (or broke a tie).
ALTER TABLE winners
    ADD COLUMN override_reason VARCHAR(255) NULL AFTER set_by;

INSERT INTO permissions (perm_key, area, label, kind, scope) VALUES
    ('results.publish', 'Results', 'Publish or retract results', 'write', 'edition');

-- Phase 11: what the reviewer tells the nominee (why an entry needs changes).
ALTER TABLE nominee_entries
    ADD COLUMN review_note VARCHAR(500) NULL AFTER status;