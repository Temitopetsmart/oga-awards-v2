
-- Allow Face of the Period to feature nominees OR committee members.
-- Existing nominee records remain valid.

ALTER TABLE face_of_period
  ADD COLUMN subject_type ENUM('nominee', 'committee') NOT NULL DEFAULT 'nominee'
    AFTER edition_id,
  MODIFY nominee_entry_id BIGINT UNSIGNED NULL,
  ADD COLUMN committee_assignment_id BIGINT UNSIGNED NULL
    AFTER nominee_entry_id,
  ADD KEY ix_face_committee (committee_assignment_id),
  ADD CONSTRAINT fk_face_committee
    FOREIGN KEY (committee_assignment_id)
    REFERENCES edition_assignments(id)
    ON DELETE CASCADE;
