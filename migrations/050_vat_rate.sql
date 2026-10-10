-- Migration: 050_vat_rate
-- IVA of every product (includes/vat.php). NULL = not set yet: vatFillMissing() fills it with
-- the suggested rate (table menu 10%; Menu cassa / online: bread 4%, drinks 22%, the rest 10%),
-- then it is edited in Admin › Menu, Menu cassa, Menu online. The fiscal receipt puts each
-- line on the printer department of its rate (Admin › Stampanti › IVA e reparti).

ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(4,2) NULL DEFAULT NULL AFTER base_price;
