-- MariaDB Database Schema for Betting Game with Event Sourcing
-- Version: 1.0
-- Engine: InnoDB for ACID compliance and foreign keys

-- Drop existing tables (in correct order due to foreign keys)
DROP TABLE IF EXISTS projection_state;
DROP TABLE IF EXISTS event_publisher;
DROP TABLE IF EXISTS snapshot;
DROP TABLE IF EXISTS event_stream;
DROP TABLE IF EXISTS event_store;
DROP TABLE IF EXISTS fee;
DROP TABLE IF EXISTS participant_score;
DROP TABLE IF EXISTS prize_distribution;
DROP TABLE IF EXISTS point_configuration;
DROP TABLE IF EXISTS result;
DROP TABLE IF EXISTS prediction;
DROP TABLE IF EXISTS event;
DROP TABLE IF EXISTS betting_game;
DROP TABLE IF EXISTS game_type;
DROP TABLE IF EXISTS participant;
DROP TABLE IF EXISTS user;

-- Core Domain Tables (Projections)

CREATE TABLE user (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME NULL,
    is_active BOOLEAN DEFAULT TRUE,
    INDEX idx_email (email),
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participant (
    participant_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    display_name VARCHAR(50) NOT NULL,
    registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (user_id) REFERENCES user(user_id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_display_name (display_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE game_type (
    game_type_id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(50) NOT NULL,
    category ENUM('sports', 'lottery') NOT NULL,
    description TEXT,
    UNIQUE KEY uk_type_name (type_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE betting_game (
    betting_game_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    game_type_id INT NOT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    status ENUM('upcoming', 'active', 'ended', 'cancelled') DEFAULT 'upcoming',
    base_fee DECIMAL(10, 2) NULL,
    fee_period_days INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (game_type_id) REFERENCES game_type(game_type_id),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_game_type (game_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event (
    event_id INT AUTO_INCREMENT PRIMARY KEY,
    betting_game_id INT NOT NULL,
    event_name VARCHAR(100) NOT NULL,
    external_event_id VARCHAR(100) NULL COMMENT 'Reference to external system',
    event_date DATETIME NOT NULL,
    status ENUM('upcoming', 'live', 'finished', 'cancelled') DEFAULT 'upcoming',
    deadline DATETIME NOT NULL COMMENT 'Prediction deadline',
    event_details JSON NULL,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (betting_game_id) REFERENCES betting_game(betting_game_id) ON DELETE CASCADE,
    INDEX idx_betting_game (betting_game_id),
    INDEX idx_event_date (event_date),
    INDEX idx_deadline (deadline),
    INDEX idx_external_id (external_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prediction (
    prediction_id VARCHAR(36) PRIMARY KEY COMMENT 'UUID',
    participant_id INT NOT NULL,
    event_id INT NOT NULL,
    prediction_data JSON NOT NULL,
    submitted_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    version INT DEFAULT 0 COMMENT 'Optimistic locking',
    FOREIGN KEY (participant_id) REFERENCES participant(participant_id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES event(event_id) ON DELETE CASCADE,
    UNIQUE KEY uk_participant_event (participant_id, event_id),
    INDEX idx_participant (participant_id),
    INDEX idx_event (event_id),
    INDEX idx_submitted_at (submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE result (
    result_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL UNIQUE,
    result_data JSON NOT NULL,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    source VARCHAR(100) NULL COMMENT 'Data source',
    FOREIGN KEY (event_id) REFERENCES event(event_id) ON DELETE CASCADE,
    INDEX idx_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE point_configuration (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    betting_game_id INT NOT NULL UNIQUE,
    scoring_rule_name VARCHAR(50) NOT NULL,
    points_exact_match INT NOT NULL,
    points_close_match INT DEFAULT 0,
    points_partial_match INT DEFAULT 0,
    points_wrong INT DEFAULT 0,
    configuration_json JSON NULL COMMENT 'Extended configuration',
    FOREIGN KEY (betting_game_id) REFERENCES betting_game(betting_game_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prize_distribution (
    distribution_id INT AUTO_INCREMENT PRIMARY KEY,
    betting_game_id INT NOT NULL UNIQUE,
    total_prize_pool DECIMAL(15, 2) NOT NULL,
    distribution_schema VARCHAR(50) NOT NULL COMMENT 'e.g., percentage, fixed',
    rank_percentages JSON NOT NULL COMMENT 'Percentage per rank',
    min_winners INT DEFAULT 1,
    max_winners INT NULL,
    FOREIGN KEY (betting_game_id) REFERENCES betting_game(betting_game_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE participant_score (
    score_id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    betting_game_id INT NOT NULL,
    event_id INT NOT NULL,
    prediction_id VARCHAR(36) NULL,
    points_earned INT NULL,
    prize_amount DECIMAL(10, 2) NULL,
    calculated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (participant_id) REFERENCES participant(participant_id) ON DELETE CASCADE,
    FOREIGN KEY (betting_game_id) REFERENCES betting_game(betting_game_id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES event(event_id) ON DELETE CASCADE,
    FOREIGN KEY (prediction_id) REFERENCES prediction(prediction_id) ON DELETE SET NULL,
    INDEX idx_participant (participant_id),
    INDEX idx_betting_game (betting_game_id),
    INDEX idx_event (event_id),
    INDEX idx_calculated_at (calculated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee (
    fee_id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    betting_game_id INT NOT NULL,
    amount DECIMAL(10, 2) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    paid_at DATETIME NULL,
    payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending',
    payment_method VARCHAR(50) NULL,
    FOREIGN KEY (participant_id) REFERENCES participant(participant_id) ON DELETE CASCADE,
    FOREIGN KEY (betting_game_id) REFERENCES betting_game(betting_game_id) ON DELETE CASCADE,
    INDEX idx_participant (participant_id),
    INDEX idx_betting_game (betting_game_id),
    INDEX idx_payment_status (payment_status),
    INDEX idx_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Event Sourcing Tables

CREATE TABLE event_store (
    event_store_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    aggregate_type VARCHAR(50) NOT NULL COMMENT 'prediction, participant, betting_game, etc.',
    aggregate_id VARCHAR(100) NOT NULL COMMENT 'Aggregate root identifier',
    version BIGINT NOT NULL COMMENT 'Event version for this aggregate',
    event_type VARCHAR(100) NOT NULL COMMENT 'prediction.submitted, prediction.updated, etc.',
    event_data JSON NOT NULL COMMENT 'Event payload',
    metadata JSON NULL COMMENT 'Event metadata',
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
    stream_id VARCHAR(100) PRIMARY KEY COMMENT 'Usually same as aggregate_id',
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
    aggregate_state JSON NOT NULL COMMENT 'Serialized aggregate state',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stream_id) REFERENCES event_stream(stream_id) ON DELETE CASCADE,
    INDEX idx_stream_version (stream_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE projection_state (
    projection_name VARCHAR(100) PRIMARY KEY COMMENT 'e.g., prediction_read_model',
    last_processed_position BIGINT NOT NULL DEFAULT 0 COMMENT 'Last event_store_id processed',
    status ENUM('active', 'rebuilding', 'failed') DEFAULT 'active',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    error_message TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_publisher (
    publisher_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    last_published_position BIGINT NOT NULL DEFAULT 0,
    last_published_at DATETIME NULL,
    INDEX idx_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Participation tracking (many-to-many between participant and betting_game)
CREATE TABLE game_participation (
    participation_id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    betting_game_id INT NOT NULL,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending_approval', 'active', 'ended') DEFAULT 'pending_approval',
    left_at DATETIME NULL,
    FOREIGN KEY (participant_id) REFERENCES participant(participant_id) ON DELETE CASCADE,
    FOREIGN KEY (betting_game_id) REFERENCES betting_game(betting_game_id) ON DELETE CASCADE,
    UNIQUE KEY uk_participant_game (participant_id, betting_game_id),
    INDEX idx_participant (participant_id),
    INDEX idx_betting_game (betting_game_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert sample game types
INSERT INTO game_type (type_name, category, description) VALUES
('Football', 'sports', 'Football/Soccer matches'),
('Basketball', 'sports', 'Basketball games'),
('Lotto 6 aus 49', 'lottery', 'German lottery 6 from 49'),
('EuroMillions', 'lottery', 'European lottery');

-- Insert sample projection states
INSERT INTO projection_state (projection_name, last_processed_position, status) VALUES
('prediction_read_model', 0, 'active'),
('score_read_model', 0, 'active'),
('leaderboard_read_model', 0, 'active');
