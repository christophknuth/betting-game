-- Demo data for the read-only demo.
--
-- Loaded after database/schema.sql. Covers the three states a prediction can be
-- in, so the read model has something to derive from:
--   - submitted : deadline still open, editable
--   - pending   : deadline passed, no result yet
--   - evaluated : result recorded, points awarded

USE betting_game;

INSERT INTO user (user_id, username, password_hash, email) VALUES
    (100, 'alice', 'x', 'alice@example.com'),
    (101, 'bob',   'x', 'bob@example.com'),
    (102, 'carol', 'x', 'carol@example.com');

INSERT INTO participant (participant_id, user_id, display_name, registered_at, is_active, version) VALUES
    (1, 100, 'Alice', '2026-01-10 09:00:00', TRUE, 1),
    (2, 101, 'Bob',   '2026-01-11 14:30:00', TRUE, 1),
    (3, 102, 'Carol', '2026-02-01 08:15:00', TRUE, 1);

-- game_type is already seeded by database/schema.sql; id 1 is 'Football'.

INSERT INTO betting_game
    (betting_game_id, name, description, game_type_id, start_date, end_date, status, base_fee, fee_period_days, version)
VALUES
    (5, 'Bundesliga 2026', 'Tippspiel zur Rueckrunde', 1,
     '2026-01-01 00:00:00', '2026-12-31 23:59:59', 'active', 10.00, 30, 1);

INSERT INTO point_configuration
    (betting_game_id, scoring_rule_name, points_exact_match, points_close_match, points_partial_match, points_wrong)
VALUES
    (5, 'default', 5, 3, 1, 0);

INSERT INTO game_participation (participant_id, betting_game_id, joined_at, status) VALUES
    (1, 5, '2026-01-12 10:00:00', 'active'),
    (2, 5, '2026-01-12 11:20:00', 'active'),
    (3, 5, '2026-02-01 09:00:00', 'pending_approval');

-- Event 41 is finished and has a result, event 42 is closed but unevaluated,
-- event 43 is still open for predictions.
INSERT INTO event (event_id, betting_game_id, event_name, external_event_id, event_date, status, deadline) VALUES
    (41, 5, 'FC Beispiel vs. SV Muster', 'ext-41', '2026-03-01 15:30:00', 'finished', '2026-03-01 15:00:00'),
    (42, 5, 'SV Muster vs. TSV Demo',    'ext-42', '2026-03-08 15:30:00', 'finished', '2026-03-08 15:00:00'),
    (43, 5, 'TSV Demo vs. FC Beispiel',  'ext-43', '2099-03-15 15:30:00', 'upcoming', '2099-03-15 15:00:00');

INSERT INTO result (result_id, event_id, result_data, recorded_at, updated_at, source) VALUES
    (1, 41, '{"homeScore": 2, "awayScore": 1}', '2026-03-01 17:35:00', NULL, 'demo-feed');

INSERT INTO prediction (prediction_id, participant_id, event_id, prediction_data, submitted_at, updated_at, version) VALUES
    -- evaluated: event 41 has a result
    ('11111111-1111-4111-8111-111111111111', 1, 41, '{"homeScore": 2, "awayScore": 1}', '2026-02-28 19:00:00', NULL, 1),
    ('22222222-2222-4222-8222-222222222222', 2, 41, '{"homeScore": 1, "awayScore": 1}', '2026-02-28 20:15:00', NULL, 1),
    -- pending: deadline passed, no result yet
    ('33333333-3333-4333-8333-333333333333', 1, 42, '{"homeScore": 0, "awayScore": 3}', '2026-03-07 12:00:00', NULL, 1),
    ('44444444-4444-4444-8444-444444444444', 2, 42, '{"homeScore": 2, "awayScore": 2}', '2026-03-07 18:45:00', '2026-03-08 09:00:00', 2),
    -- submitted: still editable
    ('55555555-5555-4555-8555-555555555555', 1, 43, '{"homeScore": 1, "awayScore": 0}', '2026-03-10 08:00:00', NULL, 1);

INSERT INTO participant_score
    (participant_id, betting_game_id, event_id, prediction_id, points_earned, prize_amount, calculated_at)
VALUES
    (1, 5, 41, '11111111-1111-4111-8111-111111111111', 5, 12.50, '2026-03-01 17:40:00'),
    (2, 5, 41, '22222222-2222-4222-8222-222222222222', 1, 0.00,  '2026-03-01 17:40:00');
