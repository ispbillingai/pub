-- Migration: 052_order_item_waiter
-- A table can be served by more than one waiter: whoever is free adds the water or the
-- dessert to a colleague's table. Each dish keeps who added it (added_by) and who sent it
-- to the kitchen (sent_by); NULL = the guest from the table page, an online order, Glovo,
-- or a dish from before this migration. Shown on the order, the slip and the order PDF.

ALTER TABLE order_items ADD COLUMN IF NOT EXISTS added_by INT NULL DEFAULT NULL;
ALTER TABLE order_items ADD COLUMN IF NOT EXISTS sent_by INT NULL DEFAULT NULL;
ALTER TABLE order_items ADD INDEX IF NOT EXISTS idx_order_items_added_by (added_by);
