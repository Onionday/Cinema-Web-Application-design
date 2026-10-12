-- Non-destructive upgrade for existing installations; safe to repeat.
-- Do NOT re-import 01_schema.sql: it resets the database.
USE cinesphere;
CREATE TABLE IF NOT EXISTS booking_seat_history (
    booking_id INT NOT NULL,
    seat_id INT NOT NULL,
    seat_row CHAR(1) NOT NULL,
    seat_number TINYINT UNSIGNED NOT NULL,
    seat_type VARCHAR(20) NOT NULL,
    seat_price DECIMAL(6,2) NOT NULL,
    PRIMARY KEY (booking_id, seat_id),
    CONSTRAINT fk_history_booking FOREIGN KEY (booking_id)
        REFERENCES bookings(booking_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Previously deleted seats cannot be reconstructed.
INSERT IGNORE INTO booking_seat_history
    (booking_id, seat_id, seat_row, seat_number, seat_type, seat_price)
SELECT bs.booking_id, bs.seat_id, s.seat_row, s.seat_number, s.seat_type, bs.seat_price
FROM booking_seats bs JOIN seats s ON s.seat_id = bs.seat_id;
