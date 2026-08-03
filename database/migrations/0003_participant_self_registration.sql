-- E1-01: a participant may sign themselves up, so a participant has an account
-- and three states instead of a yes/no flag.
--
-- `keycloak_subject` is what makes the registration self-service: identity is
-- resolved by the token's `sub` where the realm carries no participant_id
-- attribute. The unique key is the point of it - two registrations from the
-- same login would otherwise be two participants, and identity would be
-- whichever one a query found first.
--
-- `is_active` becomes `status`: pending (waiting for the administrator), active
-- (plays), inactive (refused, or left). Everybody who existed before this was
-- entered by an administrator and is therefore already decided - active unless
-- the flag said otherwise.

ALTER TABLE participant
    ADD COLUMN IF NOT EXISTS keycloak_subject VARCHAR(64) NULL
        COMMENT 'Keycloak `sub` of the account that registered'
        AFTER display_name;

ALTER TABLE participant
    ADD COLUMN IF NOT EXISTS status ENUM('pending', 'active', 'inactive') NOT NULL DEFAULT 'active'
        AFTER registered_at;

ALTER TABLE participant
    ADD UNIQUE INDEX IF NOT EXISTS uk_keycloak_subject (keycloak_subject);

ALTER TABLE participant
    ADD INDEX IF NOT EXISTS idx_status (status);

-- The one statement that cannot be written with IF EXISTS: it reads the old
-- column, and a database that never had it - a fresh one from schema.sql, where
-- this migration only runs to be recorded - would fail on the name alone. So it
-- is assembled first and only becomes an UPDATE where there is something to
-- carry over.
SET @old := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'participant' AND column_name = 'is_active'
);

SET @carry := IF(
    @old > 0,
    'UPDATE participant SET status = IF(is_active, ''active'', ''inactive'')',
    'DO 0'
);

PREPARE carry FROM @carry;

EXECUTE carry;

DEALLOCATE PREPARE carry;

ALTER TABLE participant
    DROP COLUMN IF EXISTS is_active;
