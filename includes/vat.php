<?php
/**
 * IVA: every product has its rate (menu_items.vat_rate) and the fiscal receipt puts each
 * line on the RT printer's department (reparto) programmed with that rate.
 *
 * Departments: Admin › Stampanti › "IVA e reparti" (settings.vat_departments, rate → reparto),
 * as programmed on the printer. Orobianco's RCH: reparto 1 = 4%, 2 = 10%, 3 = 22%.
 *
 * Rates are suggested from the Italian rules (DPR 633/72, Tabella A) when a product is added
 * with "Automatica" — the accountant has the last word:
 *   consumption on the premises (Menu a tavola)          → 10% (somministrazione), cover charge too
 *   takeaway (Menu cassa, Menu online): bread, grissini   → 4%
 *                                       drinks, alcohol   → 22%
 *                                       everything else   → 10% (focacce, pizze, pastries, gastronomia)
 */

require_once __DIR__ . '/settings.php';

/** Rates a product can have; 0 = esente / non imponibile. */
const VAT_RATES = [4.0, 5.0, 10.0, 22.0, 0.0];
/** The cover charge (coperto) is part of the service on the premises. */
const VAT_COVER_RATE = 10.0;
/** Until Admin › Stampanti says otherwise. */
const VAT_DEFAULT_DEPARTMENTS = ['4' => 1, '10' => 2, '22' => 3];

function vatKey(float $rate): string
{
    return rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
}

/** ['4' => 1, '10' => 2, '22' => 3]: the printer department of each rate. */
function vatDepartments(): array
{
    $map = (array) getSetting('vat_departments', VAT_DEFAULT_DEPARTMENTS);
    $out = [];
    foreach ($map as $rate => $dept) {
        if ((int) $dept > 0) $out[vatKey((float) $rate)] = (int) $dept;
    }
    return $out;
}

/** The rates a product can be given: those with a department on the printer. */
function vatRatesOffered(): array
{
    $deps = vatDepartments();
    return array_values(array_filter(VAT_RATES, static fn($r) => isset($deps[vatKey($r)])));
}

function vatLabel(float $rate): string
{
    return $rate > 0 ? vatKey($rate) . '%' : t('vat_exempt');
}

/**
 * <select> of the offered rates. $value null = "Automatica" first (new products: the rate
 * is suggested from the name on save); the product's own rate is always listed.
 */
function vatSelect(string $name, ?float $value, string $attrs = ''): string
{
    $rates = vatRatesOffered();
    if ($value !== null && !in_array($value, $rates, true)) $rates[] = $value;
    $h = '<select name="' . htmlspecialchars($name) . '" ' . $attrs . '>';
    if ($value === null) $h .= '<option value="" selected>' . htmlspecialchars(t('vat_auto')) . '</option>';
    foreach ($rates as $r) {
        $h .= '<option value="' . vatKey($r) . '"' . ($value !== null && abs($value - $r) < 0.001 ? ' selected' : '') . '>'
            . htmlspecialchars(vatLabel($r)) . '</option>';
    }
    return $h . '</select>';
}

/** The rate posted by a form: one of VAT_RATES, or null ("Automatica" / invalid). */
function vatPosted($raw): ?float
{
    if ($raw === null || $raw === '') return null;
    $r = (float) str_replace(',', '.', (string) $raw);
    foreach (VAT_RATES as $ok) {
        if (abs($ok - $r) < 0.001) return $ok;
    }
    return null;
}

/** Suggested rate for a product (see the file header). $takeaway: Menu cassa / Menu online. */
function vatSuggest(string $name, string $category = '', bool $takeaway = true): float
{
    if (!$takeaway) return 10.0;
    $n = ' ' . mb_strtolower($name) . ' ';
    $c = ' ' . mb_strtolower($category) . ' ';
    $drink = '/[^a-z](acqua|coca|cola|fanta|sprite|aranciata|chinotto|gazzosa|gassosa|cedrata|tonica|schweppes|bibit\w*|birr\w*|beer|vin[oi]|prosecco|spumante|liquor\w*|amaro|limoncello|grappa|whisky|vodka|gin|rum|succo|succhi|estath\w*|te freddo|the freddo|energy|red bull|bevand\w*|lattin\w*|crodino|spritz|aperol|campari|peroni|moretti|heineken|ichnusa)[^a-z]/u';
    if (preg_match($drink, $n) || preg_match('/[^a-z](bevand\w*|bibit\w*|drink\w*|birr\w*|vini)[^a-z]/u', $c)) return 22.0;
    $bread = '/[^a-z](pane|pani|pagnott\w*|filon\w*|ciabatt\w*|baguette|rosett\w*|francesin\w*|integrale|segale|grissin\w*|fette biscottate|frisell\w*)[^a-z]/u';
    $notBread = '/(farcit|ripien|dolce|panin|cioccolat|crema|nutella|zucchero|uvett|panettone|pandoro)/u';
    if (preg_match($bread, $n) && !preg_match($notBread, $n)) return 4.0;
    return 10.0;
}

/** Products with no rate yet (new column, or added elsewhere): the suggested one. Returns how many. */
function vatFillMissing(): int
{
    $pdo  = getDBConnection();
    $rows = $pdo->query("SELECT mi.id, mi.name, mc.name AS category, mc.till_only, mc.online_only
                         FROM menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
                         WHERE mi.vat_rate IS NULL")->fetchAll();
    $up = $pdo->prepare("UPDATE menu_items SET vat_rate = ? WHERE id = ? AND vat_rate IS NULL");
    foreach ($rows as $r) {
        $up->execute([vatSuggest((string) $r['name'], (string) $r['category'], (bool) $r['till_only'] || (bool) $r['online_only']), (int) $r['id']]);
    }
    return count($rows);
}

/**
 * The fiscal receipt's lines for an order paying $totalCents: each product (and the cover
 * charge) on its rate's department. With a discount or coupon the products can't be listed
 * at their own prices, so it prints one line per rate, the discount split in proportion.
 * Returns ['ok' => true, 'items' => [...FiscalClient/RchClient items]] or ['ok' => false, 'error', 'rate'].
 */
function fiscalLinesForOrder(int $orderId, int $totalCents): array
{
    $pdo  = getDBConnection();
    $deps = vatDepartments();
    vatFillMissing();
    $st = $pdo->prepare("
        SELECT mi.name, mi.vat_rate, oi.quantity, oi.unit_price, oi.total_price
        FROM order_items oi JOIN menu_items mi ON mi.id = oi.menu_item_id
        WHERE oi.order_id = ? AND oi.status <> 'cancelled' AND oi.total_price > 0
        ORDER BY oi.id
    ");
    $st->execute([$orderId]);
    $lines = [];
    foreach ($st->fetchAll() as $r) {
        $lines[] = ['desc' => (string) $r['name'], 'qty' => (int) $r['quantity'], 'unit' => (int) round((float) $r['unit_price'] * 100),
                    'total' => (int) round((float) $r['total_price'] * 100), 'rate' => (float) $r['vat_rate']];
    }
    $o = $pdo->prepare("SELECT number_of_people, cover_charge_per_person FROM orders WHERE id = ?");
    $o->execute([$orderId]);
    $o = $o->fetch() ?: ['number_of_people' => 0, 'cover_charge_per_person' => 0];
    $people = (int) $o['number_of_people'];
    $cover  = (int) round((float) $o['cover_charge_per_person'] * 100);
    if ($people > 0 && $cover > 0) {
        $lines[] = ['desc' => t('fiscal_cover'), 'qty' => $people, 'unit' => $cover, 'total' => $people * $cover, 'rate' => VAT_COVER_RATE];
    }
    if (!$lines) {
        // Nothing itemised (should not happen): the whole amount at the cover / service rate.
        $lines[] = ['desc' => 'ORDINE ' . $orderId, 'qty' => 1, 'unit' => $totalCents, 'total' => $totalCents, 'rate' => VAT_COVER_RATE];
    }
    foreach ($lines as &$l) {
        $k = vatKey($l['rate']);
        if (!isset($deps[$k])) return ['ok' => false, 'error' => 'vat_department_missing', 'rate' => $k];
        $l['dept'] = $deps[$k];
    }
    unset($l);

    $sum = array_sum(array_column($lines, 'total'));
    $exact = $sum === $totalCents;
    foreach ($lines as $l) {
        if ($l['qty'] * $l['unit'] !== $l['total']) { $exact = false; break; }
    }
    if ($exact) {
        return ['ok' => true, 'items' => array_map(static fn($l) => [
            'description' => $l['desc'], 'quantity' => (string) $l['qty'],
            'unitPrice' => number_format($l['unit'] / 100, 2, '.', ''), 'department' => $l['dept'],
        ], $lines)];
    }

    // Discount (or rounding): the total split by department in proportion, cents exact.
    $byDept = [];
    $rateOf = [];
    foreach ($lines as $l) {
        $byDept[$l['dept']] = ($byDept[$l['dept']] ?? 0) + $l['total'];
        $rateOf[$l['dept']] = $l['rate'];
    }
    $alloc = [];
    $given = 0;
    foreach ($byDept as $d => $gross) {
        $alloc[$d] = $sum > 0 ? intdiv($gross * $totalCents, $sum) : 0;
        $given += $alloc[$d];
    }
    arsort($byDept);
    $alloc[array_key_first($byDept)] += $totalCents - $given;   // the leftover cents on the biggest share
    $items = [];
    foreach ($alloc as $d => $cents) {
        if ($cents <= 0) continue;
        $items[] = ['description' => t('fiscal_vat_group', ['rate' => vatKey($rateOf[$d])]), 'quantity' => '1',
                    'unitPrice' => number_format($cents / 100, 2, '.', ''), 'department' => $d];
    }
    return ['ok' => true, 'items' => $items];
}
