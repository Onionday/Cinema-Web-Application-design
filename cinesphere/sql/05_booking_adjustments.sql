-- =====================================================================
--  CineSphere - 05_booking_adjustments.sql
--
--  Non-destructive upgrade for an existing database: adds the audit log
--  used by the staff "Manage booking" tools (seat refunds, seat moves and
--  staff cancellations). Users, bookings and the schedule are untouched.
--  Safe to run more than once. Fresh installs get this table from
--  01_schema.sql instead.
-- =====================================================================

USE cinesphere;

CREATE TABLE IF NOT EXISTS booking_adjustments (
    adjustment_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id    INT NOT NULL,
    staff_id      INT DEFAULT NULL,
    action        ENUM('seat_refund','seat_change','booking_cancel') NOT NULL,
    detail        VARCHAR(255) NOT NULL,
    amount_change DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    reason        VARCHAR(160) NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_adj_booking (booking_id, created_at),
    CONSTRAINT fk_adj_booking FOREIGN KEY (booking_id)
        REFERENCES bookings(booking_id) ON DELETE CASCADE,
    CONSTRAINT fk_adj_staff FOREIGN KEY (staff_id)
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
