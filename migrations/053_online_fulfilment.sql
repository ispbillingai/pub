-- Migration: 053_online_fulfilment
-- An online order is either collected at the shop or delivered (asporto): the customer
-- picks one when sending the order, with the day and time they want it for. A delivery
-- also takes the address (orders.customer_address / customer_street_number), the intercom
-- code and the phone to call (the sign-up mobile or another one). All of it prints on the
-- kitchen slip and shows on the Ordini Cassa card (includes/online_order.php).

ALTER TABLE orders ADD COLUMN IF NOT EXISTS fulfilment VARCHAR(10) NULL DEFAULT NULL;   -- 'pickup' | 'delivery'
ALTER TABLE orders ADD COLUMN IF NOT EXISTS scheduled_at DATETIME NULL DEFAULT NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivery_intercom VARCHAR(60) NULL DEFAULT NULL;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS contact_phone VARCHAR(20) NULL DEFAULT NULL;
