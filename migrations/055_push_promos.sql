-- Migration: 055_push_promos
-- The promotions sent as notifications (Admin > Clienti online). Tapping one opens the
-- online page on online.php?promo=<id>, which shows the offer again (title, text, link)
-- with "Ordina ora". Also the list of the last ones sent, with how many phones got them.

CREATE TABLE IF NOT EXISTS push_promos (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(60) NOT NULL,
    body       VARCHAR(200) NOT NULL,
    url        VARCHAR(300) NULL DEFAULT NULL,
    sent       INT NOT NULL DEFAULT 0,
    failed     INT NOT NULL DEFAULT 0,
    created_by INT NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
