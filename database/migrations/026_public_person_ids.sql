-- Random numeric Public IDs for people (nominee accounts, staff and committee, admin-created nominees).
-- Display only: every internal id, key and relationship stays exactly as it was. IDs are assigned by PHP
-- (random_int) in App\PublicId, and backfilled for existing rows by backend/tools/migrate.php.
-- public_ids is the one place that guarantees an ID is unique across ALL kinds of person.
CREATE TABLE public_ids (
    public_id  VARCHAR(12)  NOT NULL PRIMARY KEY,
    owner_type ENUM('account','person','entry') NOT NULL,
    owner_id   BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pid_owner (owner_type, owner_id)
) ENGINE=InnoDB;

ALTER TABLE accounts
    ADD COLUMN public_id VARCHAR(12) NULL AFTER id,
    ADD UNIQUE KEY uq_accounts_public_id (public_id);

ALTER TABLE people
    ADD COLUMN public_id VARCHAR(12) NULL AFTER id,
    ADD UNIQUE KEY uq_people_public_id (public_id);

-- Only nominees an admin created or imported (no account) carry their own ID; a nominee with an account
-- uses the ID of the account, so one person with several categories has ONE Public ID.
ALTER TABLE nominee_entries
    ADD COLUMN public_id VARCHAR(12) NULL AFTER id,
    ADD UNIQUE KEY uq_entries_public_id (public_id);