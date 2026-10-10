/**
 * Ordini Cassa by voice (cashier/online.php): the cashier speaks instead of typing.
 *
 *   "pane euro 2,30"            → the Menu cassa product Pane at 2,30 (price said wins)
 *   "taralli"  /  "2 taralli"   → the product at its menu price, quantity said first
 *   "varie 6 euro" / "6 euro"   → a free amount (the keypad's "Varie")
 *   "pane 2,30 poi taralli 1,50" (also "… e taralli …") → two lines
 *   "annulla" / "togli"         → removes the last line added by voice
 *
 * Products are found by their name or by the "words for the voice" set in
 * Admin › Menu cassa (e.g. "tarallini, taralli napoletani"), tolerating small
 * recognition slips (tarallo / taralli). A spoken word that matches nothing but
 * comes with a price is booked as Varie, so the amount is never lost.
 *
 * Speech recognition is the browser's (Chrome, Edge, Safari): Italian, needs
 * HTTPS and the microphone permission. parseVoice() is pure, for testing in Node.
 */
(function (root) {
    'use strict';

    // Italian number words 0–99 → digits (the recogniser usually writes digits, not always).
    const NUM_WORDS = (() => {
        const u = ['zero', 'uno', 'due', 'tre', 'quattro', 'cinque', 'sei', 'sette', 'otto', 'nove', 'dieci', 'undici', 'dodici',
                   'tredici', 'quattordici', 'quindici', 'sedici', 'diciassette', 'diciotto', 'diciannove'];
        const tens = ['venti', 'trenta', 'quaranta', 'cinquanta', 'sessanta', 'settanta', 'ottanta', 'novanta'];
        const m = { un: 1, una: 1, cento: 100 };
        u.forEach((w, i) => { m[w] = i; });
        tens.forEach((t, k) => {
            const base = (k + 2) * 10;
            m[t] = base;
            for (let d = 1; d <= 9; d++) {
                const w = (d === 1 || d === 8) ? t.slice(0, -1) + u[d] : t + u[d];   // ventuno, ventotto
                m[w] = base + d;
            }
        });
        return m;
    })();
    const VERBS = new Set(['inserisci', 'inserire', 'aggiungi', 'aggiungere', 'aggiungimi', 'metti', 'mettimi', 'dammi', 'poi', 'ancora', 'anche']);
    const UNDO = new Set(['annulla', 'cancella', 'togli', 'elimina', 'rimuovi', 'indietro']);
    const FREE = new Set(['varie', 'vari', 'varia', 'vario', 'importo', 'libero', 'generico', 'diversi', 'diverse']);
    const FILLER = new Set(['di', 'del', 'della', 'dei', 'delle', 'al', 'alla', 'a', 'il', 'lo', 'la', 'i', 'gli', 'le', 'per', 'prezzo', 'costo', 'da', 'con', 'altro', 'altra', 'altri', 'altre', 'ancora']);

    /** Lower case, no accents, € → "euro", "2,30" → "2.30", other punctuation → item break or space. */
    function norm(s) {
        return String(s || '').toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '')
            .replace(/€/g, ' euro ')
            .replace(/(\d)\s*[,.]\s*(\d)/g, '$1.$2')
            .replace(/[,;]+/g, ' poi ')
            .replace(/([a-z])(\d)/g, '$1 $2').replace(/(\d)([a-z])/g, '$1 $2')
            .replace(/[^a-z0-9.\s]/g, ' ')
            .replace(/\s+/g, ' ').trim();
    }
    function tokens(s) {
        return norm(s).split(' ').filter(Boolean).map(t => (t in NUM_WORDS ? String(NUM_WORDS[t]) : t.replace(/^\.+|\.+$/g, ''))).filter(Boolean);
    }
    const isNum = t => /^\d+(\.\d{1,2})?$/.test(t || '');
    const isInt99 = t => /^\d{1,2}$/.test(t || '');

    /**
     * "pane euro 2,30 poi 2 taralli" → {items: [{qty, words, price}]} or {undo: true}.
     * price is per piece (null = the menu price), qty null = 1.
     */
    function parseVoice(text) {
        const t = tokens(text);
        if (t.length && UNDO.has(t[0])) return { undo: true, items: [] };
        const items = [];
        let cur = { qty: null, words: [], price: null };
        const empty = () => !cur.words.length && cur.qty === null && cur.price === null;
        const flush = () => { if (cur.words.length || cur.price !== null) items.push(cur); cur = { qty: null, words: [], price: null }; };
        // A price starting at t[i] (a number): "2.30", "2 euro", "2 euro e 30", "2 e 30". Returns [price, last index used].
        const readPrice = i => {
            let p = Number(t[i]);
            let j = i;
            if (t[j + 1] === 'euro') j++;
            if (!t[i].includes('.') && t[j + 1] === 'e' && isInt99(t[j + 2])) { p += Number(t[j + 2]) / 100; j += 2; }
            if (t[j + 1] === 'centesimi' || t[j + 1] === 'euro') j++;
            return [Math.round(p * 100) / 100, j];
        };
        for (let i = 0; i < t.length; i++) {
            const w = t[i], next = t[i + 1];
            if (w === 'poi') { flush(); continue; }
            if (w === 'e') {
                // "… 2,30 e taralli": the next item; "pane e taralli": two items.
                // "focaccia e 2 saccottini": a number followed by a word is the next item's quantity.
                const after = t[i + 2];
                const nextQty = isNum(next) && after && !isNum(after) && !['euro', 'e', 'centesimi', 'poi'].includes(after);
                if (cur.price !== null || (cur.words.length && (!isNum(next) || nextQty))) flush();
                continue;
            }
            if (VERBS.has(w) && empty()) continue;
            if (w === 'euro') {
                if (isNum(next)) { const [p, j] = readPrice(i + 1); if (cur.price !== null) flush(); cur.price = p; i = j; }
                continue;
            }
            if (w === 'centesimi') continue;
            if (isNum(w)) {
                const priceLike = next === 'euro' || w.includes('.') || (cur.words.length && cur.qty !== null) || cur.words.length;
                if (!priceLike && empty()) { cur.qty = Math.max(1, Math.min(99, Number(w))); continue; }
                if (cur.price !== null) flush();
                const [p, j] = readPrice(i);
                cur.price = p; i = j;
                continue;
            }
            if (cur.price !== null) flush();      // "pane 2,30 taralli 1,50": a word after a price starts the next item
            cur.words.push(w);
        }
        flush();
        return { undo: false, items };
    }

    const key = s => norm(s).split(' ').filter(w => w && !FILLER.has(w) && !/^\d/.test(w)).join(' ');
    function lev(a, b) {
        if (a === b) return 0;
        const v = Array.from({ length: b.length + 1 }, (_, k) => k);
        for (let i = 1; i <= a.length; i++) {
            let prev = v[0]; v[0] = i;
            for (let j = 1; j <= b.length; j++) {
                const tmp = v[j];
                v[j] = Math.min(v[j] + 1, v[j - 1] + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1));
                prev = tmp;
            }
        }
        return v[b.length];
    }
    const sim = (a, b) => (a && b ? 1 - lev(a, b) / Math.max(a.length, b.length) : 0);
    // How well the spoken words fit a product phrase (0–1).
    function score(spoken, phrase) {
        if (!spoken || !phrase) return 0;
        if (spoken === phrase) return 1;
        const st = spoken.split(' '), pt = phrase.split(' ');
        const each = st.map(s => Math.max(...pt.map(p => sim(s, p))));
        const tok = each.reduce((a, b) => a + b, 0) / st.length;
        // Every word of the product said (spoken "pane integrale grande" vs product "pane integrale"), or the other way.
        const cover = pt.every(p => st.some(s => sim(s, p) >= 0.8)) || st.every(s => pt.some(p => sim(s, p) >= 0.8));
        return Math.max(sim(spoken, phrase), cover ? Math.min(0.97, tok) : tok * 0.9);
    }
    /** The best Menu cassa product for the spoken words, or null. products: [{id, name, voice}] */
    function matchProduct(words, products) {
        const spoken = key(words.join(' '));
        let best = null, bestScore = 0;
        for (const p of products) {
            const phrases = [p.name].concat(String(p.voice || '').split(/[,;\n]+/)).map(key).filter(Boolean);
            for (const ph of phrases) {
                const s = score(spoken, ph);
                if (s > bestScore) { best = p; bestScore = s; }
            }
        }
        return bestScore >= 0.72 ? best : null;
    }
    /** parseVoice + the products: [{kind: 'product'|'free'|'missing', product, qty, price, said}]. */
    function resolveVoice(text, products) {
        const parsed = parseVoice(text);
        if (parsed.undo) return { undo: true, lines: [] };
        const lines = parsed.items.map(it => {
            const said = it.words.join(' ');
            const qty = it.qty || 1;
            const k = key(said);
            if (!k || k.split(' ').some(w => FREE.has(w)) && !matchProduct(it.words, products)) {
                return it.price !== null ? { kind: 'free', qty, price: it.price, said } : { kind: 'missing', said };
            }
            const p = matchProduct(it.words, products);
            if (p) return { kind: 'product', product: p, qty, price: it.price, said };
            return it.price !== null ? { kind: 'free', qty, price: it.price, said, unknown: true } : { kind: 'missing', said };
        });
        return { undo: false, lines };
    }

    root.TillVoice = { parseVoice, resolveVoice, matchProduct, norm };
    if (typeof module !== 'undefined' && module.exports) module.exports = root.TillVoice;
    if (typeof document === 'undefined') return;

    // ---- The till page: microphone button, live transcript, lines on the ticket ----
    const SR = root.SpeechRecognition || root.webkitSpeechRecognition;
    const btn = document.getElementById('voiceBtn'), bar = document.getElementById('voiceBar'), heard = document.getElementById('voiceHeard');
    if (!btn || !bar) return;
    if (!root.tillVoiceHost) return;
    const L = root.TILL_VOICE_TEXT || {};
    const fill = (s, v) => String(s || '').replace(/\{(\w+)\}/g, (_, k) => (k in v ? v[k] : ''));
    btn.hidden = false;
    if (!SR) {
        btn.disabled = true;
        btn.title = L.unsupported || '';
        return;
    }
    let rec = null, on = false;
    const host = root.tillVoiceHost;
    const products = () => host.products();

    function addLines(res) {
        const tk = host.ticket();
        if (res.undo) {
            for (let i = tk.length - 1; i >= 0; i--) {
                if (tk[i].voice) {
                    const gone = tk.splice(i, 1)[0];
                    host.changed();
                    root.showToast(fill(L.undone, { name: gone.name || L.free }), 'info', 2000);
                    return;
                }
            }
            root.showToast(L.nothing_undo, 'warning', 2000);
            return;
        }
        const done = [], miss = [];
        for (const l of res.lines) {
            if (l.kind === 'missing') { miss.push(l.said); continue; }
            const unit = l.kind === 'free' ? l.price : (l.price !== null ? l.price : l.product.amount);
            if (!(unit > 0) || unit * l.qty > 9999.99) { miss.push(l.said || ''); continue; }
            // Same rule as the keypad: over 50 € it must be confirmed (a slip: "23" heard instead of "2,30").
            if (unit * l.qty > 50 && !root.confirm(fill(L.big, { amount: host.money(unit * l.qty) }))) continue;
            if (l.kind === 'free') {
                for (let n = 0; n < l.qty; n++) tk.push({ amount: unit, voice: true });
                done.push(L.free + ' ' + host.money(unit * l.qty));
                if (l.unknown) root.showToast(fill(L.as_free, { name: l.said }), 'warning', 4000);
                continue;
            }
            const custom = l.price !== null && Math.abs(l.price - l.product.amount) > 0.001;
            const same = tk.find(x => x.id === l.product.id && !!x.custom === custom && (!custom || Math.abs(x.unit - unit) < 0.001));
            if (same) { same.qty = Math.min(99, same.qty + l.qty); same.voice = true; }
            else tk.push({ id: l.product.id, name: l.product.name, unit, qty: l.qty, custom, voice: true });
            done.push(l.qty + '× ' + l.product.name + ' ' + host.money(unit * l.qty));
        }
        if (done.length) { host.changed(); root.showToast(fill(L.added, { items: done.join(', ') }), 'success', 2500); }
        if (miss.length) root.showToast(fill(L.not_found, { name: miss.filter(Boolean).join(', ') || '?' }), 'error', 3500);
        if (!done.length && !miss.length) root.showToast(L.nothing, 'warning', 2000);
    }
    // Of the recogniser's alternatives, the first one whose every item is understood.
    function best(alts) {
        let first = null;
        for (const a of alts) {
            const r = resolveVoice(a, products());
            if (!first) first = r;
            if (r.undo || (r.lines.length && r.lines.every(l => l.kind !== 'missing'))) return [a, r];
        }
        return [alts[0], first];
    }
    function start() {
        rec = new SR();
        rec.lang = 'it-IT';
        rec.continuous = true;
        rec.interimResults = true;
        rec.maxAlternatives = 3;
        rec.onresult = e => {
            for (let i = e.resultIndex; i < e.results.length; i++) {
                const r = e.results[i];
                if (!r.isFinal) { heard.textContent = r[0].transcript; heard.classList.add('interim'); continue; }
                const alts = Array.from(r).map(x => x.transcript).filter(Boolean);
                if (!alts.length) continue;
                const [text, res] = best(alts);
                heard.classList.remove('interim');
                heard.textContent = fill(L.heard, { text: text.trim() });
                addLines(res);
            }
        };
        rec.onerror = e => {
            if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { stop(); root.showToast(L.denied, 'error', 5000); }
        };
        // Chrome ends a session after a silence: keep listening while the button is on.
        rec.onend = () => { if (on) setTimeout(() => { if (on) try { rec.start(); } catch (err) {} }, 250); };
        try { rec.start(); } catch (err) {}
    }
    function stop() {
        on = false;
        if (rec) try { rec.abort(); } catch (err) {}
        rec = null;
        btn.classList.remove('on');
        btn.setAttribute('aria-pressed', 'false');
        bar.hidden = true;
    }
    btn.addEventListener('click', () => {
        if (on) { stop(); return; }
        on = true;
        btn.classList.add('on');
        btn.setAttribute('aria-pressed', 'true');
        bar.hidden = false;
        heard.textContent = L.listening;
        heard.classList.add('interim');
        start();
    });
    root.tillVoiceActive = () => on;
})(typeof window !== 'undefined' ? window : globalThis);
