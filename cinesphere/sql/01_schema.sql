-- =====================================================================
--  CineSphere - Cinema Ticket Booking Portal
--  IE4727 Web Application Design  |  Theme 5
--  File: 01_schema.sql   (database + table definitions)
--
--  Import order:  01_schema.sql  ->  02_seed.sql
--  Import via phpMyAdmin, or:  mysql -u root -p < 01_schema.sql
-- =====================================================================

DROP DATABASE IF EXISTS cinesphere;
CREATE DATABASE cinesphere
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;
USE cinesphere;


-- ---------------------------------------------------------------------
-- users : registered customers and administrators
-- ---------------------------------------------------------------------
CREATE TABLE users (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(80)  NOT NULL,
    email         VARCHAR(120) NOT NULL,
    phone         VARCHAR(20)  NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    birth_date    DATE         DEFAULT NULL,
    role          ENUM('customer','admin') NOT NULL DEFAULT 'customer',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME     DEFAULT NULL,
    CONSTRAINT uq_users_email UNIQUE (email)
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- cinemas : physical venues
-- ---------------------------------------------------------------------
CREATE TABLE cinemas (
    cinema_id   INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(80)  NOT NULL,
    location    VARCHAR(80)  NOT NULL,
    address     VARCHAR(200) NOT NULL,
    phone       VARCHAR(20)  NOT NULL,
    opening_hrs VARCHAR(60)  NOT NULL DEFAULT '10:00 - 01:00 daily'
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- halls : screening rooms inside a cinema
-- ---------------------------------------------------------------------
CREATE TABLE halls (
    hall_id       INT AUTO_INCREMENT PRIMARY KEY,
    cinema_id     INT NOT NULL,
    hall_name     VARCHAR(40) NOT NULL,
    hall_type     ENUM('Standard','Premium','IMAX') NOT NULL DEFAULT 'Standard',
    row_count     TINYINT UNSIGNED NOT NULL,
    seats_per_row TINYINT UNSIGNED NOT NULL,
    CONSTRAINT fk_halls_cinema FOREIGN KEY (cinema_id)
        REFERENCES cinemas(cinema_id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- seats : one physical seat in one hall
-- ---------------------------------------------------------------------
CREATE TABLE seats (
    seat_id     INT AUTO_INCREMENT PRIMARY KEY,
    hall_id     INT NOT NULL,
    seat_row    CHAR(1)          NOT NULL,
    seat_number TINYINT UNSIGNED NOT NULL,
    seat_type   ENUM('Standard','Premium','Couch') NOT NULL DEFAULT 'Standard',
    surcharge   DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT uq_seat_in_hall UNIQUE (hall_id, seat_row, seat_number),
    CONSTRAINT fk_seats_hall FOREIGN KEY (hall_id)
        REFERENCES halls(hall_id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- movies
-- ---------------------------------------------------------------------
CREATE TABLE movies (
    movie_id     INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(120) NOT NULL,
    tagline      VARCHAR(160) NOT NULL DEFAULT '',
    synopsis     TEXT         NOT NULL,
    genre        VARCHAR(60)  NOT NULL,
    language     VARCHAR(40)  NOT NULL DEFAULT 'English',
    duration_min SMALLINT UNSIGNED NOT NULL,
    classification ENUM('G','PG','PG13','NC16','M18') NOT NULL DEFAULT 'PG13',
    director     VARCHAR(80)  NOT NULL,
    cast_list    VARCHAR(255) NOT NULL,
    release_date DATE         NOT NULL,
    poster       VARCHAR(80)  NOT NULL,
    banner       VARCHAR(80)  NOT NULL DEFAULT '',
    user_score   DECIMAL(3,1) NOT NULL DEFAULT 0.0,
    status       ENUM('now_showing','coming_soon','archived')
                 NOT NULL DEFAULT 'now_showing',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_movies_status ON movies (status);
CREATE INDEX idx_movies_genre  ON movies (genre);


-- ---------------------------------------------------------------------
-- showtimes : one screening of one movie, in one hall, at one time
-- ---------------------------------------------------------------------
CREATE TABLE showtimes (
    showtime_id INT AUTO_INCREMENT PRIMARY KEY,
    movie_id    INT  NOT NULL,
    hall_id     INT  NOT NULL,
    show_date   DATE NOT NULL,
    show_time   TIME NOT NULL,
    format      ENUM('2D','3D','IMAX') NOT NULL DEFAULT '2D',
    base_price  DECIMAL(6,2) NOT NULL,
    status      ENUM('open','sold_out','cancelled') NOT NULL DEFAULT 'open',
    CONSTRAINT uq_hall_slot UNIQUE (hall_id, show_date, show_time),
    CONSTRAINT fk_show_movie FOREIGN KEY (movie_id)
        REFERENCES movies(movie_id) ON DELETE CASCADE,
    CONSTRAINT fk_show_hall  FOREIGN KEY (hall_id)
        REFERENCES halls(hall_id)  ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_show_date ON showtimes (show_date, show_time);


-- ---------------------------------------------------------------------
-- preferences : the customer's ranked short-list, built BEFORE booking.
-- The theme requires the customer to be able to lodge several choices in
-- order of preference and then make a final selection from whichever of
-- them are still available.
-- ---------------------------------------------------------------------
CREATE TABLE preferences (
    pref_id     INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    showtime_id INT NOT NULL,
    rank_order  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    seat_count  TINYINT UNSIGNED NOT NULL,
    est_total   DECIMAL(8,2) NOT NULL,
    note        VARCHAR(160) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pref_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_pref_show FOREIGN KEY (showtime_id)
        REFERENCES showtimes(showtime_id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- preference_seats : the specific seats held inside one preference entry
-- ---------------------------------------------------------------------
CREATE TABLE preference_seats (
    pref_seat_id INT AUTO_INCREMENT PRIMARY KEY,
    pref_id      INT NOT NULL,
    seat_id      INT NOT NULL,
    seat_price   DECIMAL(6,2) NOT NULL,
    CONSTRAINT uq_pref_seat UNIQUE (pref_id, seat_id),
    CONSTRAINT fk_ps_pref FOREIGN KEY (pref_id)
        REFERENCES preferences(pref_id) ON DELETE CASCADE,
    CONSTRAINT fk_ps_seat FOREIGN KEY (seat_id)
        REFERENCES seats(seat_id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- bookings : a confirmed (or failed / cancelled) transaction
-- ---------------------------------------------------------------------
CREATE TABLE bookings (
    booking_id     INT AUTO_INCREMENT PRIMARY KEY,
    booking_ref    VARCHAR(16) NOT NULL,
    user_id        INT NOT NULL,
    showtime_id    INT NOT NULL,
    seat_count     TINYINT UNSIGNED NOT NULL,
    total_amount   DECIMAL(8,2) NOT NULL,
    booking_status ENUM('confirmed','cancelled','failed') NOT NULL DEFAULT 'confirmed',
    payment_status ENUM('paid','unpaid','refunded')       NOT NULL DEFAULT 'unpaid',
    payment_method VARCHAR(40) NOT NULL DEFAULT 'Third-party gateway',
    email_sent     TINYINT(1)  NOT NULL DEFAULT 0,
    booked_at      DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_booking_ref UNIQUE (booking_ref),
    CONSTRAINT fk_book_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE,
    CONSTRAINT fk_book_show FOREIGN KEY (showtime_id)
        REFERENCES showtimes(showtime_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_book_user ON bookings (user_id, booked_at);


-- ---------------------------------------------------------------------
-- booking_seats : which seats a booking occupies.
-- The UNIQUE key on (showtime_id, seat_id) is what physically prevents
-- two customers from ever holding the same seat for the same screening.
-- ---------------------------------------------------------------------
CREATE TABLE booking_seats (
    booking_seat_id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id      INT NOT NULL,
    showtime_id     INT NOT NULL,
    seat_id         INT NOT NULL,
    seat_price      DECIMAL(6,2) NOT NULL,
    CONSTRAINT uq_seat_per_show UNIQUE (showtime_id, seat_id),
    CONSTRAINT fk_bs_booking FOREIGN KEY (booking_id)
        REFERENCES bookings(booking_id) ON DELETE CASCADE,
    CONSTRAINT fk_bs_show FOREIGN KEY (showtime_id)
        REFERENCES showtimes(showtime_id) ON DELETE CASCADE,
    CONSTRAINT fk_bs_seat FOREIGN KEY (seat_id)
        REFERENCES seats(seat_id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- contact_messages : feedback / enquiry form submissions
-- ---------------------------------------------------------------------
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

CREATE TABLE contact_messages (
    msg_id       INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(80)  NOT NULL,
    email        VARCHAR(120) NOT NULL,
    phone        VARCHAR(20)  NOT NULL DEFAULT '',
    subject      VARCHAR(60)  NOT NULL,
    rating       TINYINT UNSIGNED NOT NULL DEFAULT 5,
    message      TEXT NOT NULL,
    msg_status   ENUM('new','read','resolved') NOT NULL DEFAULT 'new',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ---------------------------------------------------------------------
-- A read-only view used by showtimes.php so that the seat-availability
-- arithmetic lives in the database rather than being repeated in PHP.
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW v_showtime_availability AS
SELECT  st.showtime_id,
        st.movie_id,
        st.hall_id,
        st.show_date,
        st.show_time,
        st.format,
        st.base_price,
        st.status,
        m.title,
        m.classification,
        m.duration_min,
        m.poster,
        h.hall_name,
        h.hall_type,
        c.cinema_id,
        c.name     AS cinema_name,
        c.location AS cinema_location,
        (h.row_count * h.seats_per_row) AS total_seats,
        (SELECT COUNT(*)
           FROM booking_seats bs
           JOIN bookings b ON b.booking_id = bs.booking_id
          WHERE bs.showtime_id = st.showtime_id
            AND b.booking_status = 'confirmed') AS seats_taken
FROM showtimes st
JOIN movies  m ON m.movie_id  = st.movie_id
JOIN halls   h ON h.hall_id   = st.hall_id
JOIN cinemas c ON c.cinema_id = h.cinema_id;
