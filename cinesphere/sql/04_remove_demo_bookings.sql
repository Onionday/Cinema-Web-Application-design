-- Remove only the original demo bookings; preserve website-created bookings.
-- Back up the database before importing. Safe to run more than once.
-- Foreign keys cascade to booking_seats and booking_seat_history.
USE cinesphere;
START TRANSACTION;
DELETE FROM bookings
WHERE (booking_id, booking_ref, user_id, showtime_id) IN (
    (1, 'CS100001', 2, 4),
    (2, 'CS100002', 3, 4),
    (3, 'CS100003', 3, 4),
    (4, 'CS100004', 2, 39),
    (5, 'CS100005', 3, 39),
    (6, 'CS100006', 2, 78),
    (7, 'CS100007', 3, 15),
    (8, 'CS100008', 2, 112),
    (9, 'CS100009', 3, 60),
    (10, 'CS100010', 2, 33)
);
COMMIT;
