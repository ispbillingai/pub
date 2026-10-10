-- Migration: 054_web_push
-- Web Push notifications to the online customers' phones, also with the page and the
-- browser closed (includes/web_push.php). One row per browser that said yes ("Attiva le
-- notifiche" in online.php): where to send (endpoint) and its keys. promos = 1: it also
-- gets the promotions sent from Admin > Clienti online. Gone ones (404/410) go inactive.

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    online_customer_id INT NULL DEFAULT NULL,
    endpoint           VARCHAR(1000) NOT NULL,
    endpoint_hash      CHAR(64) NOT NULL,
    p256dh             VARCHAR(120) NOT NULL,
    auth               VARCHAR(40) NOT NULL,
    promos             TINYINT(1) NOT NULL DEFAULT 0,
    active             TINYINT(1) NOT NULL DEFAULT 1,
    failures           INT NOT NULL DEFAULT 0,
    user_agent         VARCHAR(255) NULL DEFAULT NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_ok_at         DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    INDEX idx_push_customer (online_customer_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
