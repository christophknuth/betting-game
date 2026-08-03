-- Demo participants for the Keycloak users in keycloak/realm-export.json.
--
-- The basis version has no endpoint that creates a participant (self-registration
-- is E1-01), so these rows are written directly - the same shortcut QUICKSTART.md
-- takes in step 3. The participant_id values must match the `participant_id`
-- claim the realm hands out, or the participant views resolve to nobody.
--
-- What that costs: `participant` is a projection. These rows stand in no event,
-- so a `POST /admin/projections/participant_read_model/rebuild` wipes them. Fine
-- for a walkthrough or an E2E run, wrong for production data.
--
-- Idempotent on purpose: the E2E suite runs this before every session.
--
--   docker-compose exec -T db mariadb -uroot -psecret betting_game \
--     < database/seed-demo-participants.sql

INSERT INTO user (user_id, username, password_hash, email) VALUES
  (1, 'admin', 'x', 'admin@example.com'),
  (2, 'testuser', 'x', 'test@example.com')
ON DUPLICATE KEY UPDATE username = VALUES(username);

INSERT INTO participant (participant_id, user_id, display_name, status) VALUES
  (1, 1, 'Admin', 'active'),
  (2, 2, 'Test User', 'active')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), status = 'active';
