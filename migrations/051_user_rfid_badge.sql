-- Migration: 051_user_rfid_badge
-- Badge RFID of a staff user (Admin > Utenti): read by a USB reader that types like a
-- keyboard, it logs that user in (login page) or switches the till to them (staffBadgeLogin).
-- At Ordini Cassa each operator then sees only their own ticket and counter sales.

ALTER TABLE users ADD COLUMN IF NOT EXISTS rfid_code VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uq_users_rfid_code (rfid_code);
