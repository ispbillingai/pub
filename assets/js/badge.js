/**
 * Staff badge (RFID). A USB reader "types" the badge's code very fast and
 * presses Enter, like a keyboard. Such a burst, outside text fields (or in a
 * field marked data-badge, the login page's), goes to /api/badge.php: the
 * badge's user takes over this device (another operator at the same till).
 *
 * A page that also reads other codes this way (Ordini Cassa: products,
 * customers' QR) sets window.onBadgeRead(code) → true when it took the code.
 * staffBadgeRead(code) → the API's answer, for pages that call it themselves.
 */
(function () {
    const MAX_GAP = 50;   // ms between two keys of a reader; people type slower
    const MIN_LEN = 6;    // shorter bursts are not a badge (BADGE_MIN_LENGTH)
    let buf = '', last = 0, busy = false;

    function badgeMessage(text) {
        if (typeof window.showToast === 'function') { window.showToast(text, 'error', 4000); return; }
        const box = document.getElementById('badgeMsg');
        if (box) { box.textContent = text; box.hidden = false; }
    }

    async function staffBadgeRead(code) {
        if (busy) return { success: false };
        busy = true;
        try {
            const r = await (await fetch('/api/badge.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code, page: location.pathname }) })).json();
            if (r.success) location.href = r.redirect;
            return r;
        } catch (e) {
            return { success: false };
        } finally {
            busy = false;
        }
    }
    window.staffBadgeRead = staffBadgeRead;

    document.addEventListener('keydown', e => {
        const t = e.target;
        if (t.closest && t.closest('input, textarea, select, [contenteditable]') && !t.closest('[data-badge]')) { buf = ''; return; }
        const now = performance.now();
        if (now - last > MAX_GAP) buf = '';
        last = now;
        if (e.key === 'Enter') {
            const code = buf;
            buf = '';
            if (code.length < MIN_LEN) return;
            e.preventDefault();
            e.stopPropagation();
            if (typeof window.onBadgeRead === 'function' && window.onBadgeRead(code)) return;
            staffBadgeRead(code).then(r => {
                if (r.success) return;
                if (t.matches && t.matches('[data-badge]')) t.value = '';
                if (r.message) badgeMessage(r.message);
            });
            return;
        }
        if (e.key && e.key.length === 1) buf += e.key;
        else if (e.key !== 'Shift') buf = '';
    }, true);
})();
