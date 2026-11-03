ALTER TABLE committee_applications ADD COLUMN appointed_person_id BIGINT UNSIGNED NULL AFTER decision_reason;
ALTER TABLE committee_applications ADD COLUMN appointed_at DATETIME NULL AFTER appointed_person_id;
ALTER TABLE committee_applications ADD COLUMN onboarding_message TEXT NULL AFTER appointed_at;
ALTER TABLE committee_applications ADD CONSTRAINT fk_ca_person FOREIGN KEY (appointed_person_id) REFERENCES people(id);