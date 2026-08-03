-- A ticket is handed in for a Laufzeit in weeks and a choice of draw days,
-- instead of for a period typed out by hand.
--
-- Both columns are NULL for everything handed in before this: the event log is
-- immutable, those tickets carry no Laufzeit, and period_end and draw_count
-- still say what they played. Nothing is invented for them.

ALTER TABLE ticket
    ADD COLUMN IF NOT EXISTS duration_weeks TINYINT UNSIGNED NULL
        COMMENT 'Laufzeit in weeks, as chosen at submission'
        AFTER period_end;

ALTER TABLE ticket
    ADD COLUMN IF NOT EXISTS draw_days ENUM('wednesday', 'saturday', 'both') NULL
        COMMENT 'Which of the two weekly draws the ticket takes part in'
        AFTER duration_weeks;
