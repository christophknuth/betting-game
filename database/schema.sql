-- MariaDB schema: lottery syndicate (Lotto 6 aus 49)
-- Version: 2.0 - base version
-- Engine: InnoDB for ACID compliance and foreign keys
--
-- Core idea: each participant has exactly one bet row per BET PERIOD. The unique key on
-- bet_row(participant_id, bet_period_id) enforces that structurally, not as a check in
-- code.
--
-- How long a period runs is up to the administrator: a single period spanning the whole
-- tipp year yields "one row per year", twelve monthly periods allow a monthly change.
-- Periods of one tipp year must not overlap - otherwise two rows of the same participant
-- would be valid on the same day.
--
-- The schema of the sports-betting extension lives in schema-e2-sports.sql.

-- Drop existing tables (in correct order due to foreign keys)
DROP TABLE IF EXISTS command_log;
DROP TABLE IF EXISTS projection_state;
DROP TABLE IF EXISTS event_publisher;
DROP TABLE IF EXISTS snapshot;
DROP TABLE IF EXISTS event_stream;
DROP TABLE IF EXISTS event_store;
DROP TABLE IF EXISTS payout_share;
DROP TABLE IF EXISTS payout;
DROP TABLE IF EXISTS fee;
DROP TABLE IF EXISTS ticket_row_match;
DROP TABLE IF EXISTS ticket_draw_result;
DROP TABLE IF EXISTS draw;
DROP TABLE IF EXISTS ticket_row;
DROP TABLE IF EXISTS ticket;
DROP TABLE IF EXISTS bet_row;
DROP TABLE IF EXISTS bet_period;
DROP TABLE IF EXISTS membership;
DROP TABLE IF EXISTS tipp_year;
DROP TABLE IF EXISTS participant;
DROP TABLE IF EXISTS user;

-- ============================================================
-- Master data
-- ============================================================

CREATE TABLE user (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL,
    is_active BOOLEAN DEFAULT TRUE,
    UNIQUE KEY uk_username (username),
    UNIQUE KEY uk_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participant (
    participant_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL COMMENT 'Optional - guest participants have no account',
    display_name VARCHAR(50) NOT NULL,
    registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (user_id) REFERENCES user(user_id) ON DELETE SET NULL,
    UNIQUE KEY uk_user (user_id),
    INDEX idx_display_name (display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tipp year and membership
-- ============================================================

CREATE TABLE tipp_year (
    tipp_year_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL COMMENT 'Freely defined, not tied to the calendar',
    end_date DATE NOT NULL,
    status ENUM('planned', 'running', 'closed', 'distributed') DEFAULT 'planned',
    ticket_cost_per_row DECIMAL(10, 2) NOT NULL COMMENT 'Cost of one row per draw',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    -- 1 while the year is running, NULL otherwise. Equal NULLs do not collide
    -- in a unique key, equal ones do - so the key below carries the rule "at
    -- most one running tipp year" without constraining the other states.
    running_marker TINYINT GENERATED ALWAYS AS (IF(status = 'running', 1, NULL)) STORED,
    UNIQUE KEY uk_single_running_year (running_marker)
        COMMENT 'Enforces: at most one tipp year is running at a time',
    INDEX idx_status (status),
    INDEX idx_period (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE membership (
    membership_id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    tipp_year_id INT NOT NULL,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME NULL,
    status ENUM('active', 'ended') DEFAULT 'active',
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (participant_id) REFERENCES participant(participant_id) ON DELETE CASCADE,
    FOREIGN KEY (tipp_year_id) REFERENCES tipp_year(tipp_year_id) ON DELETE CASCADE,
    UNIQUE KEY uk_participant_year (participant_id, tipp_year_id),
    INDEX idx_tipp_year (tipp_year_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Bet period: the freely chosen validity window of a bet row
-- ============================================================

CREATE TABLE bet_period (
    bet_period_id INT AUTO_INCREMENT PRIMARY KEY,
    tipp_year_id INT NOT NULL,
    name VARCHAR(100) NOT NULL COMMENT 'e.g. "2026 full year", "Q1 2026", "March 2026"',
    start_date DATE NOT NULL COMMENT 'Freely chosen by the administrator',
    end_date DATE NOT NULL,
    sequence INT NOT NULL DEFAULT 1 COMMENT 'Order within the tipp year',
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (tipp_year_id) REFERENCES tipp_year(tipp_year_id) ON DELETE CASCADE,
    UNIQUE KEY uk_year_start (tipp_year_id, start_date),
    INDEX idx_tipp_year (tipp_year_id),
    INDEX idx_range (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Bet row: one per participant and bet period
-- ============================================================

CREATE TABLE bet_row (
    bet_row_id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    bet_period_id INT NOT NULL,
    numbers JSON NOT NULL COMMENT 'Six distinct numbers 1-49, sorted ascending',
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (participant_id) REFERENCES participant(participant_id) ON DELETE CASCADE,
    FOREIGN KEY (bet_period_id) REFERENCES bet_period(bet_period_id) ON DELETE CASCADE,
    UNIQUE KEY uk_participant_period (participant_id, bet_period_id)
        COMMENT 'Enforces: one row per participant per period',
    INDEX idx_bet_period (bet_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- The monthly ticket
-- ============================================================

CREATE TABLE ticket (
    ticket_id INT AUTO_INCREMENT PRIMARY KEY,
    tipp_year_id INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    lottery_reference VARCHAR(100) NULL COMMENT 'Receipt id from the lottery operator',
    superzahl TINYINT NULL COMMENT '0-9, from the ticket serial - applies to every row',
    row_count INT NOT NULL DEFAULT 0,
    draw_count INT NOT NULL DEFAULT 0,
    total_cost DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    status ENUM('draft', 'submitted', 'settled') DEFAULT 'draft',
    submitted_at DATETIME NULL,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (tipp_year_id) REFERENCES tipp_year(tipp_year_id) ON DELETE CASCADE,
    UNIQUE KEY uk_year_period (tipp_year_id, period_start),
    INDEX idx_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_row (
    ticket_row_id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    bet_row_id INT NOT NULL,
    numbers JSON NOT NULL COMMENT 'Snapshot at submission - a later correction does not change it',
    FOREIGN KEY (ticket_id) REFERENCES ticket(ticket_id) ON DELETE CASCADE,
    FOREIGN KEY (bet_row_id) REFERENCES bet_row(bet_row_id) ON DELETE CASCADE,
    UNIQUE KEY uk_ticket_bet_row (ticket_id, bet_row_id),
    INDEX idx_bet_row (bet_row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Draws and winnings
-- ============================================================

CREATE TABLE draw (
    draw_id INT AUTO_INCREMENT PRIMARY KEY,
    tipp_year_id INT NOT NULL,
    draw_date DATE NOT NULL,
    numbers JSON NULL COMMENT 'The six drawn numbers, sorted ascending',
    superzahl TINYINT NULL COMMENT '0-9',
    status ENUM('scheduled', 'drawn', 'evaluated') DEFAULT 'scheduled',
    recorded_at DATETIME NULL,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (tipp_year_id) REFERENCES tipp_year(tipp_year_id) ON DELETE CASCADE,
    UNIQUE KEY uk_draw_date (draw_date),
    INDEX idx_tipp_year (tipp_year_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_draw_result (
    ticket_draw_result_id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id INT NOT NULL,
    draw_id INT NOT NULL,
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00 COMMENT 'What the whole ticket won',
    winning_classes JSON NULL COMMENT 'Class -> row count and amount',
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (ticket_id) REFERENCES ticket(ticket_id) ON DELETE CASCADE,
    FOREIGN KEY (draw_id) REFERENCES draw(draw_id) ON DELETE CASCADE,
    UNIQUE KEY uk_ticket_draw (ticket_id, draw_id),
    INDEX idx_draw (draw_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_row_match (
    ticket_row_match_id INT AUTO_INCREMENT PRIMARY KEY,
    ticket_row_id INT NOT NULL,
    draw_id INT NOT NULL,
    matched_numbers TINYINT NOT NULL COMMENT '0-6',
    superzahl_matched BOOLEAN NOT NULL DEFAULT FALSE,
    winning_class TINYINT NULL COMMENT '1-9, null when nothing was won',
    amount DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    calculated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_row_id) REFERENCES ticket_row(ticket_row_id) ON DELETE CASCADE,
    FOREIGN KEY (draw_id) REFERENCES draw(draw_id) ON DELETE CASCADE,
    UNIQUE KEY uk_row_draw (ticket_row_id, draw_id),
    INDEX idx_draw (draw_id),
    INDEX idx_winning_class (winning_class)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Gebuehren
-- ============================================================

CREATE TABLE fee (
    fee_id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    ticket_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL COMMENT 'Ticket cost divided by the participants on it',
    due_date DATE NOT NULL,
    payment_status ENUM('open', 'paid', 'waived') DEFAULT 'open',
    paid_at DATETIME NULL,
    payment_method VARCHAR(50) NULL,
    booked_by VARCHAR(100) NULL COMMENT 'Admin who recorded the payment',
    note VARCHAR(255) NULL,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (participant_id) REFERENCES participant(participant_id) ON DELETE CASCADE,
    FOREIGN KEY (ticket_id) REFERENCES ticket(ticket_id) ON DELETE CASCADE,
    UNIQUE KEY uk_participant_ticket (participant_id, ticket_id),
    INDEX idx_ticket (ticket_id),
    INDEX idx_payment_status (payment_status),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Jahresausschuettung
-- ============================================================

CREATE TABLE payout (
    payout_id INT AUTO_INCREMENT PRIMARY KEY,
    tipp_year_id INT NOT NULL,
    total_winnings DECIMAL(12, 2) NOT NULL,
    participant_count INT NOT NULL,
    share_per_participant DECIMAL(12, 2) NOT NULL,
    distributed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    booked_by VARCHAR(100) NULL,
    note VARCHAR(255) NULL,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (tipp_year_id) REFERENCES tipp_year(tipp_year_id) ON DELETE CASCADE,
    UNIQUE KEY uk_tipp_year (tipp_year_id) COMMENT 'One distribution per year'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payout_share (
    payout_share_id INT AUTO_INCREMENT PRIMARY KEY,
    payout_id INT NOT NULL,
    participant_id INT NOT NULL,
    amount DECIMAL(12, 2) NOT NULL COMMENT 'Equal share, independent of periods paid',
    payment_status ENUM('open', 'paid') DEFAULT 'open',
    paid_at DATETIME NULL,
    FOREIGN KEY (payout_id) REFERENCES payout(payout_id) ON DELETE CASCADE,
    FOREIGN KEY (participant_id) REFERENCES participant(participant_id) ON DELETE CASCADE,
    UNIQUE KEY uk_payout_participant (payout_id, participant_id),
    INDEX idx_participant (participant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Event Sourcing
-- ============================================================

CREATE TABLE event_store (
    event_store_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    aggregate_type VARCHAR(50) NOT NULL COMMENT 'tipp_year, bet_period, bet_row, ticket, draw, participant',
    aggregate_id VARCHAR(100) NOT NULL,
    version BIGINT NOT NULL,
    event_type VARCHAR(100) NOT NULL COMMENT 'bet_row.assigned, draw.recorded, etc.',
    event_data JSON NOT NULL,
    metadata JSON NULL,
    occurred_at DATETIME(6) NOT NULL COMMENT 'Microsecond precision',
    causation_id VARCHAR(36) NULL COMMENT 'Command ID that caused this event',
    correlation_id VARCHAR(36) NULL COMMENT 'Trace ID for distributed tracing',
    UNIQUE KEY uk_aggregate_version (aggregate_type, aggregate_id, version),
    INDEX idx_aggregate (aggregate_type, aggregate_id),
    INDEX idx_event_type (event_type),
    INDEX idx_occurred_at (occurred_at),
    INDEX idx_correlation_id (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_stream (
    stream_id VARCHAR(100) PRIMARY KEY COMMENT 'aggregateType-aggregateId',
    aggregate_type VARCHAR(50) NOT NULL,
    aggregate_id VARCHAR(100) NOT NULL,
    current_version BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_aggregate (aggregate_type, aggregate_id),
    INDEX idx_aggregate_type (aggregate_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE snapshot (
    snapshot_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    stream_id VARCHAR(100) NOT NULL,
    version BIGINT NOT NULL,
    aggregate_state JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stream_id) REFERENCES event_stream(stream_id) ON DELETE CASCADE,
    UNIQUE KEY uk_stream_version (stream_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_publisher (
    publisher_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    last_published_position BIGINT NOT NULL DEFAULT 0,
    last_published_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE projection_state (
    projection_name VARCHAR(100) PRIMARY KEY,
    publisher_id BIGINT NULL,
    last_processed_position BIGINT NOT NULL DEFAULT 0,
    status ENUM('running', 'rebuilding', 'stopped', 'failed') DEFAULT 'running',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    error_message TEXT NULL,
    FOREIGN KEY (publisher_id) REFERENCES event_publisher(publisher_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE command_log (
    command_id VARCHAR(36) PRIMARY KEY COMMENT 'UUID, returned as CommandResponse.commandId',
    idempotency_key VARCHAR(64) NULL,
    command_type VARCHAR(100) NOT NULL,
    issued_by_participant_id INT NULL,
    aggregate_type VARCHAR(50) NULL,
    aggregate_id VARCHAR(100) NULL,
    status ENUM('accepted', 'processing', 'completed', 'failed') DEFAULT 'accepted',
    event_store_position BIGINT NULL,
    resource_id INT NULL,
    http_status SMALLINT NULL COMMENT 'Status of the original response, replayed on an idempotent retry',
    response_body JSON NULL,
    error_message TEXT NULL,
    correlation_id VARCHAR(36) NULL,
    accepted_at DATETIME(6) NOT NULL,
    completed_at DATETIME(6) NULL,
    FOREIGN KEY (issued_by_participant_id) REFERENCES participant(participant_id) ON DELETE SET NULL,
    UNIQUE KEY uk_idempotency_key (idempotency_key),
    INDEX idx_status (status),
    INDEX idx_correlation_id (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Projektionen
-- ============================================================

-- One row per projector (see src/Infrastructure/Projection). The names have to
-- match Projector::name(), because that is how a rebuild finds its state.
INSERT INTO projection_state (projection_name, last_processed_position, status) VALUES
('participant_read_model', 0, 'running'),
('tipp_year_read_model', 0, 'running'),
('bet_period_read_model', 0, 'running'),
('bet_row_read_model', 0, 'running'),
('ticket_read_model', 0, 'running'),
('draw_read_model', 0, 'running'),
('fee_read_model', 0, 'running');
