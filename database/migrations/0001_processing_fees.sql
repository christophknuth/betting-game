-- The Bearbeitungsentgelt per Spielauftrag.
--
-- Two rates are agreed for the season and live on the tipp year; what a ticket
-- was actually charged is kept on the ticket, because a rate that changes must
-- not move the cost of an order that has already been handed in.
--
-- Both default to zero, so a syndicate that is not charged one keeps the totals
-- it had before this ran.

ALTER TABLE tipp_year
    ADD COLUMN IF NOT EXISTS processing_fee_single_week DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Fee for a Spielauftrag covering at most one week'
        AFTER ticket_cost_per_row;

ALTER TABLE tipp_year
    ADD COLUMN IF NOT EXISTS processing_fee_multi_week DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Fee for a Spielauftrag running longer than one week'
        AFTER processing_fee_single_week;

ALTER TABLE ticket
    ADD COLUMN IF NOT EXISTS processing_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00
        COMMENT 'Bearbeitungsentgelt charged for this Spielauftrag, as a snapshot'
        AFTER draw_count;
