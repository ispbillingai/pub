-- Migration: 056_push_promo_target
-- A promotion can be meant for one customer only (a test sent to one phone): then only
-- they see it in the offers box of online.php. NULL = everybody (the normal case).

ALTER TABLE push_promos ADD COLUMN IF NOT EXISTS target_customer_id INT NULL DEFAULT NULL;
