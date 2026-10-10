-- Migration: 057_push_promo_targets
-- A notification sent from Admin > Clienti online to chosen customers (one or more) instead
-- of everybody who accepts promotions: who it was for. Only they see it in the offers box
-- of online.php. A promotion with no rows here is for everybody.
-- Replaces push_promos.target_customer_id (one customer only), copied over.

CREATE TABLE IF NOT EXISTS push_promo_targets (
    promo_id    INT NOT NULL,
    customer_id INT NOT NULL,
    PRIMARY KEY (promo_id, customer_id),
    INDEX idx_push_promo_targets_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO push_promo_targets (promo_id, customer_id)
    SELECT id, target_customer_id FROM push_promos WHERE target_customer_id IS NOT NULL;
