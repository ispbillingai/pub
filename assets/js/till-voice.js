/**
 * Ordini Cassa by voice (cashier/online.php): the cashier speaks instead of typing.
 *
 * Products:
 *   "pane euro 2,30"            → the Menu cassa product Pane at 2,30 (price said wins)
 *   "taralli"  /  "2 taralli"   → the product at its menu price, quantity said first
 *   "varie 6 euro" / "6 euro"   → a free amount (the keypad's "Varie")
 *   "pane 2,30 poi taralli 1,50" (also "… e taralli …") → two lines
 *   "annulla" / "togli"         → removes the last line added by voice; "togli saccottino" → one less
 * Till buttons: "incassa", "totale" (also said aloud), "svuota" (+ "sì"), "fotocamera", "aiuto".
 * Orders waiting below (counter sales left open, online orders): "incassa" with an empty ticket,
 *   "incassa Mario", "incassa 13 euro", "incassa banco 2,40", "incassa l'ultimo" open their payment.
 * Payment window: "carta", "contanti", "contanti senza scontrino", "stampa conto",
 *   "sconto 10 per cento" / "sconto 5 euro", "conferma", "chiudi", "fatto",
 *   "pagamento virtuale" (test mode button; the spoken command is its confirmation).
 * Wake phrase: with "Si attiva con «ok cassa»" ticked (per device) the microphone waits from
 * page load; "ok cassa" (also "ok cassa, pane 2,30") starts listening, 30 s of silence or
 * "basta" / "stop cassa" go back to waiting for "ok cassa".
 *
 * Products are found by their name or by the "words for the voice" set in
 * Admin › Menu cassa (e.g. "tarallini, taralli napoletani"), tolerating small
 * recognition slips (tarallo / taralli). A spoken word that matches nothing but
 * comes with a price is booked as Varie, so the amount is never lost.
 *
 * Speech recognition is the browser's (Chrome, Edge, Safari): Italian, needs
 * HTTPS and the microphone permission. The parsing functions are pure, for testing in Node.
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

    /**
     * The wake phrase: "ok cassa" (also okay / ehi / hey / ciao cassa, or a phrase that starts with
     * "cassa"). Returns {woke, rest}: rest = what was said after it ("ok cassa, pane 2,30").
     */
    function stripWake(text) {
        const s = String(text || '');
        const m = s.match(/\b(?:ok(?:ay)?|ehi|hey|ei|ciao)[\s,.!]*cassa\b[\s,.!:]*/i) || s.match(/^\s*cassa\b[\s,.!:]*/i);
        return m ? { woke: true, rest: s.slice(m.index + m[0].length).trim() } : { woke: false, rest: s };
    }

    /**
     * A command instead of products: {cmd, …} or null. ctx 'till' (the ticket) or 'pay' (the payment window).
     *   till: checkout, total, clear, camera, remove {words}
     *   pay:  card, cash, cash_nf, print, discount {type: 'percent'|'fixed'|'', value}, confirm, close, done, virtual
     *   both: stop, help, yes, no
     */
    function parseCommand(text, ctx) {
        const n = tokens(text).join(' ').replace(/^(per favore|per piacere) /, '').replace(/ (per favore|per piacere|grazie)$/, '');
        const is = re => re.test(n);
        if (is(/^(basta|stop|spegni|smetti( di ascoltare)?|fine ascolto|grazie)( grazie)?$/)) return { cmd: 'stop' };
        if (is(/^(aiuto|comandi|elenco( dei)? comandi|cosa posso dire)$/)) return { cmd: 'help' };
        if (ctx === 'pay') {
            if (is(/(^| )virtuale$/)) return { cmd: 'virtual' };
            if (is(/^((paga|pagamento|pagare) )?(con )?(la )?(carta( di credito)?|bancomat|pos)$/)) return { cmd: 'card' };
            if (is(/(contanti|cash).*(senza scontrino|non fiscale)$/) || is(/^(senza scontrino|non fiscale)$/)) return { cmd: 'cash_nf' };
            if (is(/^((paga|pagamento|pagare) )?(in |con )?(i )?(contanti|cash)$/)) return { cmd: 'cash' };
            if (is(/^(stampa|stampami)( il| lo)?( conto| preconto| scontrino)?$/) || is(/^preconto$/)) return { cmd: 'print' };
            if (is(/^(togli|rimuovi|elimina|nessuno|niente|senza)( lo)? sconto$/)) return { cmd: 'discount', type: '', value: 0 };
            let m = n.match(/^(?:fai |applica )?(?:uno )?sconto (?:del |di )?(\d+(?:\.\d{1,2})?)(?: (euro|per ?cento|percento|per 100))?$/);
            if (m) return { cmd: 'discount', type: m[2] === 'euro' ? 'fixed' : 'percent', value: Number(m[1]) };
            if (is(/^(si|conferma|confermo|completa|ok|va bene)$/)) return { cmd: 'confirm' };
            if (is(/^(chiudi|esci|indietro|annulla|torna indietro|torna alla cassa|lascia stare)$/)) return { cmd: 'close' };
            if (is(/^(fatto|fine|ok fatto|nuova vendita|finito)$/)) return { cmd: 'done' };
            return null;
        }
        if (is(/^(chiudi|esci|indietro|torna indietro|fatto|fine)$/)) return { cmd: 'noop' };     // payment words with no payment open
        if (is(/^(si|conferma|confermo|ok|va bene|certo)$/)) return { cmd: 'yes' };
        if (is(/^(no|lascia stare|niente|lascia)$/)) return { cmd: 'no' };
        if (is(/^(incassa|paga|pagamento|vai al pagamento|procedi( al pagamento)?|chiudi( lo)? scontrino|fai( il)? conto)$/)) return { cmd: 'checkout' };
        const who = n.match(/^(?:incassa|fai pagare|paga) (?:(?:l |il |lo |la )?(?:ordine|conto|vendita) )?(?:di |del |della |dello |a )?(.+)$/);
        if (who) return { cmd: 'collect', words: who[1].split(' ') };
        if (is(/^(totale|quanto fa|quanto viene|quanto e|dimmi( il)? totale|il totale)$/)) return { cmd: 'total' };
        if (is(/^(svuota( lo scontrino| tutto| lo)?|cancella tutto|azzera( tutto| lo scontrino)?|nuovo scontrino)$/)) return { cmd: 'clear' };
        if (is(/^((apri|accendi|spegni|chiudi) )?(la )?(fotocamera|camera|telecamera)$/)) return { cmd: 'camera' };
        const m = n.match(/^(?:togli|rimuovi|elimina|leva|cancella)(?: un| una| 1)? (.+)$/);
        if (m && !/^(l )?ultim[oa]( riga)?$/.test(m[1])) return { cmd: 'remove', words: m[1].split(' ') };
        return null;
    }

    /**
     * Which waiting order "incassa …" means. list: [{id, kind: 'sale'|'online', name, total, at}].
     * Returns {match}, {ambiguous: [...]} or {none: true}.
     */
    function pickCollect(words, list) {
        if (!list.length) return { none: true };
        const said = (words || []).join(' ');
        const k = key(said);
        const byTime = list.slice().sort((a, b) => String(a.at).localeCompare(String(b.at)));
        if (!k && !/\d/.test(said)) return list.length === 1 ? { match: list[0] } : { ambiguous: byTime };
        if (/(^| )(ultim[oa]|recente)( |$)/.test(k)) return { match: byTime[byTime.length - 1] };
        if (/(^| )prim[oa]( |$)/.test(k)) return { match: byTime[0] };
        const item = parseVoice(said).items[0] || { words: [], price: null, qty: null };
        let price = item.price;
        const bare = tokens(said);
        if (price === null && bare.length === 1 && isNum(bare[0])) price = Number(bare[0]);   // "incassa 13"
        const nameWords = item.words.filter(w => !['ordine', 'conto', 'vendita', 'cliente', 'banco', 'euro'].includes(w));
        let pool = list;
        if (item.words.some(w => w === 'banco' || w === 'vendita')) pool = pool.filter(o => o.kind === 'sale');
        if (price !== null) pool = pool.filter(o => Math.abs(o.total - price) < 0.005);
        if (nameWords.length) {
            const named = pool.filter(o => o.name);
            const m = matchProduct(nameWords, named.map(o => ({ id: o.id, name: o.name })));
            pool = m ? named.filter(o => o.id === m.id) : [];
        }
        if (pool.length === 1) return { match: pool[0] };
        return pool.length ? { ambiguous: pool } : { ambiguous: byTime, notFound: true };
    }

    /** "stop cassa" (also "spegni / disattiva il microfono"): back to waiting for "ok cassa". */
    function isOff(text) {
        const n = norm(text).replace(/ poi /g, ' ');
        return /(^| )(stop|stoppa|spegni|disattiva|chiudi)( la| il)? cassa( |$)/.test(n)
            || /(^| )(spegni|disattiva|chiudi|stacca)( il)? microfono( |$)/.test(n);
    }

    root.TillVoice = { parseVoice, resolveVoice, matchProduct, parseCommand, stripWake, pickCollect, isOff, norm };
    if (typeof module !== 'undefined' && module.exports) module.exports = root.TillVoice;
    if (typeof document === 'undefined') return;

    // ---- The till page ----
    // off → (button) active; with "Si attiva con «ok cassa»" ticked the microphone waits in
    // standby from page load, "ok cassa" → active, 30 s of silence or "basta" → standby.
    const SR = root.SpeechRecognition || root.webkitSpeechRecognition;
    const btn = document.getElementById('voiceBtn'), bar = document.getElementById('voiceBar'), heard = document.getElementById('voiceHeard');
    const wakeBox = document.getElementById('voiceWake');
    if (!btn || !bar || !root.tillVoiceHost) return;
    const host = root.tillVoiceHost;
    const L = root.TILL_VOICE_TEXT || {};
    const fill = (s, v) => String(s || '').replace(/\{(\w+)\}/g, (_, k) => (k in v ? v[k] : ''));
    const toast = (msg, type = 'info', ms = 2500) => root.showToast && root.showToast(msg, type, ms);
    btn.hidden = false;
    if (!SR) {
        btn.disabled = true;
        btn.title = L.unsupported || '';
        return;
    }
    const WAKE_KEY = 'till-voice-wake';
    // An earlier version kept the microphone off after "stop cassa", across reloads: forget that.
    try { localStorage.removeItem('till-voice-off'); } catch (e) {}
    const IDLE_MS = 30000;
    let rec = null, mode = 'off', idleTimer = null, pending = null, speakingUntil = 0;
    const wakeOn = () => !!(wakeBox && wakeBox.checked);
    if (wakeBox) {
        wakeBox.closest('label').hidden = false;
        try { wakeBox.checked = localStorage.getItem(WAKE_KEY) === '1'; } catch (e) {}
    }
    const products = () => host.products();

    // Short tones: up = listening, down = back to standby.
    let actx = null;
    function tone(up) {
        try {
            actx = actx || new (root.AudioContext || root.webkitAudioContext)();
            if (actx.state === 'suspended') actx.resume();
            [[up ? 660 : 880, 0], [up ? 990 : 550, 0.12]].forEach(([f, t]) => {
                const o = actx.createOscillator(), g = actx.createGain();
                o.frequency.value = f;
                g.gain.setValueAtTime(0.0001, actx.currentTime + t);
                g.gain.exponentialRampToValueAtTime(0.25, actx.currentTime + t + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, actx.currentTime + t + 0.16);
                o.connect(g).connect(actx.destination);
                o.start(actx.currentTime + t); o.stop(actx.currentTime + t + 0.18);
            });
        } catch (e) {}
    }
    // The till speaks (the total). Results heard meanwhile are its own voice: ignored.
    function say(text) {
        if (!root.speechSynthesis) return;
        try {
            const u = new SpeechSynthesisUtterance(text);
            u.lang = 'it-IT';
            // Until it ends (or its rough length, if the browser never says it ended).
            speakingUntil = Date.now() + Math.min(6000, 1200 + String(text).length * 80);
            u.onend = u.onerror = () => { speakingUntil = Date.now() + 700; };
            root.speechSynthesis.cancel();
            root.speechSynthesis.speak(u);
        } catch (e) {}
    }
    function show(text, interim) {
        heard.textContent = text;
        heard.classList.toggle('interim', !!interim);
    }
    function setMode(m) {
        mode = m;
        btn.classList.toggle('on', m === 'active');
        btn.classList.toggle('standby', m === 'standby');
        btn.setAttribute('aria-pressed', m === 'active' ? 'true' : 'false');
        bar.hidden = m === 'off';
        clearTimeout(idleTimer);
        if (m === 'active') { show(L.listening, true); touch(); }
        if (m === 'standby') show(L.standby, true);
        if (m === 'off') stopEngine(); else startEngine();
    }
    // Active and quiet for 30 s: back to standby (only when the wake phrase is on).
    function touch() {
        clearTimeout(idleTimer);
        if (wakeOn()) idleTimer = setTimeout(() => { if (mode === 'active') { tone(false); setMode('standby'); } }, IDLE_MS);
    }

    function addLines(res) {
        const tk = host.ticket();
        if (res.undo) {
            for (let i = tk.length - 1; i >= 0; i--) {
                if (tk[i].voice) {
                    const gone = tk.splice(i, 1)[0];
                    host.changed();
                    toast(fill(L.undone, { name: gone.name || L.free }), 'info', 2000);
                    return;
                }
            }
            toast(L.nothing_undo, 'warning', 2000);
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
                if (l.unknown) toast(fill(L.as_free, { name: l.said }), 'warning', 4000);
                continue;
            }
            const custom = l.price !== null && Math.abs(l.price - l.product.amount) > 0.001;
            const same = tk.find(x => x.id === l.product.id && !!x.custom === custom && (!custom || Math.abs(x.unit - unit) < 0.001));
            if (same) { same.qty = Math.min(99, same.qty + l.qty); same.voice = true; }
            else tk.push({ id: l.product.id, name: l.product.name, unit, qty: l.qty, custom, voice: true });
            done.push(l.qty + '× ' + l.product.name + ' ' + host.money(unit * l.qty));
        }
        if (done.length) { host.changed(); toast(fill(L.added, { items: done.join(', ') }), 'success', 2500); }
        if (miss.length) toast(fill(L.not_found, { name: miss.filter(Boolean).join(', ') || '?' }), 'error', 3500);
        if (!done.length && !miss.length) toast(L.nothing, 'warning', 2000);
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

    // Pressing a button of the payment window (same site, in the overlay).
    function payButton(doc, selector) {
        const el = [...doc.querySelectorAll(selector)].find(b => b.offsetParent !== null && !b.disabled);
        if (!el) { toast(L.no_btn, 'warning', 2500); return false; }
        el.click();
        return true;
    }
    function runPay(c) {
        const doc = host.payDoc();
        if (!doc) return;
        const visible = sel => { const el = doc.querySelector(sel); return !!(el && el.offsetParent !== null); };
        switch (c.cmd) {
            case 'card':    payButton(doc, '[onclick^="payCard"], [onclick^="payDojo"]'); break;
            case 'cash_nf': payButton(doc, '[onclick="payCash(true)"]'); break;
            case 'cash':
                if (doc.querySelector('[onclick="payCash()"]')) { payButton(doc, '[onclick="payCash()"]'); break; }
                // No cash machine: the manual payment, set to cash, waits for "conferma".
                if (!visible('#k-manual')) payButton(doc, '[onclick^="toggleManual"]');
                if (doc.getElementById('manualMethod')) doc.getElementById('manualMethod').value = 'cash';
                toast(L.manual_cash, 'info', 5000);
                break;
            case 'print':   payButton(doc, '[onclick^="printBill"]'); break;
            case 'discount': {
                const t = doc.getElementById('discountType'), v = doc.getElementById('discountValue');
                if (!t || !v) { toast(L.no_btn, 'warning'); break; }
                t.value = c.type; v.value = c.type ? String(c.value) : '0';
                if (payButton(doc, '[onclick^="applyDiscountAction"]')) {
                    toast(c.type ? fill(L.discount, { d: c.type === 'percent' ? c.value + '%' : host.money(c.value) }) : L.discount_off, 'success');
                }
                break;
            }
            case 'confirm': payButton(doc, '[onclick^="payManual"], #d-sig-accept'); break;
            case 'close':
                if (visible('[onclick^="cancelCash"]')) { payButton(doc, '[onclick^="cancelCash"]'); break; }
                if (visible('#d-cancel')) { payButton(doc, '#d-cancel'); break; }
                payButton(doc, '[onclick*="leavePay(false)"]');
                break;
            case 'done':    payButton(doc, '[onclick*="leavePay(true)"]'); break;
            case 'virtual': {
                // Test mode only (Settings › Modalità test). Its "are you sure?" box can't be answered
                // by voice: the spoken "pagamento virtuale" is the confirmation.
                const w = doc.defaultView, ask = w.confirm;
                w.confirm = () => true;
                try { payButton(doc, '[onclick^="payVirtual"]'); } finally { setTimeout(() => { w.confirm = ask; }, 0); }
                break;
            }
        }
    }
    // "incassa …": one of the orders waiting below the till (open counter sales, online orders).
    function collect(words) {
        const r = pickCollect(words, host.collectables());
        if (r.none) { toast(L.collect_none, 'warning', 3000); return; }
        if (r.match) { host.openPay(r.match.id); toast(L.pay_open, 'info', 6000); return; }
        const list = r.ambiguous.slice(0, 4).map(o => (o.name || L.collect_counter) + ' ' + host.money(o.total)).join(' · ');
        toast(fill(r.notFound ? L.collect_unknown : L.collect_which, { list, said: words.join(' ') }), 'warning', 7000);
    }
    function runTill(c) {
        const tk = host.ticket();
        switch (c.cmd) {
            case 'checkout':
                if (!tk.length) { collect([]); break; }
                host.checkout();
                toast(L.pay_open, 'info', 6000);
                break;
            case 'collect': collect(c.words); break;
            case 'noop': toast(L.no_btn, 'info', 2000); break;
            case 'total': {
                const tot = host.total();
                const e = Math.floor(tot + 1e-9), cents = Math.round((tot - e) * 100);
                toast(fill(L.total, { total: host.money(tot) }), 'info', 4000);
                say(fill(L.total_say, { euro: e, cents: cents ? ' e ' + cents : '' }));
                break;
            }
            case 'clear':
                if (!tk.length) { toast(L.empty, 'warning'); break; }
                pending = { cmd: 'clear', until: Date.now() + 10000 };
                toast(L.confirm_clear, 'warning', 6000);
                break;
            case 'camera': host.camera(); break;
            case 'remove': {
                const p = matchProduct(c.words, tk.filter(l => l.id).map(l => ({ id: l.id, name: l.name, voice: (products().find(x => x.id === l.id) || {}).voice })));
                const i = p ? tk.map(l => l.id).lastIndexOf(p.id) : -1;
                if (i < 0) { toast(fill(L.not_in_ticket, { name: c.words.join(' ') }), 'warning'); break; }
                if (tk[i].qty > 1) tk[i].qty--; else tk.splice(i, 1);
                host.changed();
                toast(fill(L.removed_one, { name: p.name }), 'info', 2000);
                break;
            }
        }
    }
    function handle(alts) {
        if (Date.now() < speakingUntil) return;
        // "stop cassa": back to waiting for "ok cassa" (off, if the wake phrase is not on).
        if (alts.some(isOff)) {
            if (mode === 'active') tone(false);
            if (wakeOn()) setMode('standby'); else setMode('off');
            return;
        }
        if (mode === 'standby') {
            const w = alts.map(stripWake).find(x => x.woke);
            if (!w) return;
            tone(true);
            setMode('active');
            if (!w.rest) return;
            alts = [w.rest];
        } else {
            // "ok cassa" again while listening: nothing to do; "ok cassa, pane 2,30": the rest.
            const ws = alts.map(stripWake);
            if (ws[0].woke && !ws[0].rest) { touch(); show(L.listening, true); return; }
            alts = ws.map((w, i) => (w.woke ? w.rest : alts[i])).filter(Boolean);
        }
        touch();
        const ctx = host.payDoc() ? 'pay' : 'till';
        show(fill(L.heard, { text: alts[0].trim() }), false);
        const c = alts.map(a => parseCommand(a, ctx)).find(Boolean);
        if (pending && Date.now() > pending.until) pending = null;
        if (pending && c && (c.cmd === 'yes' || c.cmd === 'no')) {
            if (c.cmd === 'yes' && pending.cmd === 'clear') { host.clear(); toast(L.cleared, 'success'); }
            pending = null;
            return;
        }
        pending = null;
        if (c && c.cmd === 'stop') { if (wakeOn()) { tone(false); setMode('standby'); } else setMode('off'); return; }
        if (c && c.cmd === 'help') { show(ctx === 'pay' ? L.help_pay : L.help, false); return; }
        if (ctx === 'pay') { if (c) runPay(c); else toast(L.in_pay, 'warning', 3500); return; }
        if (c && (c.cmd === 'yes' || c.cmd === 'no')) return;          // nothing waiting for a yes / no
        // A payment command with no payment open ("sconto 10 per cento") must never become an amount.
        const payCmd = alts.map(a => parseCommand(a, 'pay')).find(Boolean);
        if ((!c || c.cmd === 'collect') && payCmd && ['card', 'cash', 'cash_nf', 'print', 'discount', 'virtual'].includes(payCmd.cmd)) { toast(L.pay_first, 'warning', 3500); return; }
        if (c) { runTill(c); return; }
        const [, res] = best(alts);
        addLines(res);
    }

    // The recogniser runs while the mode is standby or active; Chrome stops it after a silence: restart.
    function startEngine() {
        if (rec) return;
        rec = new SR();
        rec.lang = 'it-IT';
        rec.continuous = true;
        rec.interimResults = true;
        rec.maxAlternatives = 3;
        rec.onresult = e => {
            for (let i = e.resultIndex; i < e.results.length; i++) {
                const r = e.results[i];
                if (!r.isFinal) { if (mode === 'active') show(r[0].transcript, true); continue; }
                const alts = Array.from(r).map(x => x.transcript).filter(Boolean);
                if (alts.length) handle(alts);
            }
        };
        rec.onerror = e => {
            if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
                // Mic refused, or a start with no tap yet on this page: one tap on "Voce" fixes it.
                const asked = mode;
                setMode('off');
                toast(asked === 'standby' ? L.restart : L.denied, asked === 'standby' ? 'warning' : 'error', 6000);
            }
        };
        rec.onend = () => {
            if (mode === 'off' || !rec) return;
            const r = rec;
            setTimeout(() => { if (mode !== 'off' && rec === r) try { r.start(); } catch (err) {} }, 250);
        };
        try { rec.start(); } catch (err) {}
    }
    function stopEngine() {
        const r = rec;
        rec = null;
        if (r) try { r.abort(); } catch (err) {}
    }

    btn.addEventListener('click', () => {
        if (mode === 'active') { if (wakeOn()) { tone(false); setMode('standby'); } else setMode('off'); return; }
        tone(true);
        setMode('active');
    });
    if (wakeBox) wakeBox.addEventListener('change', () => {
        try { localStorage.setItem(WAKE_KEY, wakeBox.checked ? '1' : '0'); } catch (e) {}
        if (wakeBox.checked && mode === 'off') setMode('standby');
        if (!wakeBox.checked && mode === 'standby') setMode('off');
        if (mode === 'active') touch();
    });
    if (wakeOn()) setMode('standby');
    // Only a spoken session holds the page's auto-refresh; the standby comes back by itself after a reload.
    root.tillVoiceActive = () => mode === 'active';
})(typeof window !== 'undefined' ? window : globalThis);
