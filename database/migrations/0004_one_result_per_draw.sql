-- 0004: one result per draw, not one per ticket and draw
--
-- `ticket_draw_result` was unique on (ticket_id, draw_id). Recording the
-- winnings of a draw again is meant to correct the figure, and against the same
-- ticket it did. But the covering ticket is worked out from the draw's date, so
-- where two tickets overlap a second recording could land on the other one -
-- and then the correction was not a correction but a second row. The draw
-- appeared twice in the list, and both amounts went into the year's total.
--
-- A draw is played by exactly one ticket. The key says so now.
--
-- Idempotent: the DELETE finds nothing once it has run, and the index changes
-- carry IF EXISTS / IF NOT EXISTS.

-- The newest row per draw wins - it is the correction, the ones before it are
-- what was corrected. This has to happen before uk_draw can exist.
DELETE r FROM ticket_draw_result r
JOIN (
    SELECT draw_id, MAX(ticket_draw_result_id) AS keep_id
    FROM ticket_draw_result
    GROUP BY draw_id
) newest ON newest.draw_id = r.draw_id
WHERE r.ticket_draw_result_id < newest.keep_id;

-- A row evaluation belongs to the ticket the draw's result names. Where a
-- correction moved the draw onto another ticket, the rows of the one it left
-- stayed behind as results of a draw they never played.
--
-- This reaches the evaluated draws. For one whose winnings are not recorded
-- yet there is no result to compare against - rebuilding the `draw_read_model`
-- projection is what puts those right.
DELETE m FROM ticket_row_match m
JOIN ticket_row tr ON tr.ticket_row_id = m.ticket_row_id
JOIN ticket_draw_result r ON r.draw_id = m.draw_id
WHERE tr.ticket_id <> r.ticket_id;

-- Before dropping uk_ticket_draw: it is the index the foreign key on
-- ticket_id rests on, and MariaDB refuses to drop the last one a key can use.
ALTER TABLE ticket_draw_result ADD INDEX IF NOT EXISTS idx_ticket (ticket_id);

ALTER TABLE ticket_draw_result ADD UNIQUE INDEX IF NOT EXISTS uk_draw (draw_id);

ALTER TABLE ticket_draw_result DROP INDEX IF EXISTS uk_ticket_draw;

-- Redundant once uk_draw exists, and it also carried the foreign key on draw_id
-- until now - which is why it is dropped after uk_draw, not before.
ALTER TABLE ticket_draw_result DROP INDEX IF EXISTS idx_draw;
