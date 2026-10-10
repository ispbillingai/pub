-- Migration: 049_till_voice_words
-- Ordini Cassa by voice (assets/js/till-voice.js): besides its name, a Menu cassa
-- product is recognised by these words, comma separated (e.g. "tarallini, taralli napoletani").

ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS voice_words VARCHAR(255) NULL DEFAULT NULL AFTER barcode;
