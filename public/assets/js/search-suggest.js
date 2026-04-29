// Navbar live-search dropdown. Debounced fetch to /api/search?q=…, renders
// up to 8 suggestions, supports keyboard (↑/↓/Enter/Esc), closes on
// click-outside. No Twig values interpolated.
(function () {
    const input    = document.getElementById('aa-search-input');
    const dropdown = document.getElementById('aa-search-dropdown');
    if (!input || !dropdown) return;

    let timer = null;
    let lastQuery = '';
    let activeIndex = -1;

    const esc = (s) => String(s).replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));

    function timeLeft(endTime) {
        const diff = (new Date(endTime).getTime() - Date.now()) / 1000;
        if (diff <= 0) return 'Ended';
        const d = Math.floor(diff / 86400);
        const h = Math.floor((diff % 86400) / 3600);
        const m = Math.floor((diff % 3600) / 60);
        if (d > 0) return d + 'd ' + h + 'h';
        if (h > 0) return h + 'h ' + m + 'm';
        return m + 'm';
    }

    function render(results, query) {
        activeIndex = -1;
        if (results.length === 0) {
            dropdown.innerHTML = '<div class="aa-search-empty">No matches for &ldquo;' + esc(query) + '&rdquo;</div>';
            dropdown.hidden = false;
            return;
        }
        dropdown.innerHTML = results.map(r => `
            <a href="${esc(r.url)}" class="aa-search-item">
                <span class="aa-search-thumb">
                    ${r.primary_image
                        ? `<img src="${esc(r.primary_image)}" alt="">`
                        : '<i class="bi bi-image"></i>'}
                </span>
                <span class="aa-search-text">
                    <span class="aa-search-title">${esc(r.title)}</span>
                    <span class="aa-search-meta">$${Number(r.current_price).toFixed(2)} &middot; ${esc(timeLeft(r.end_time))}</span>
                </span>
            </a>
        `).join('');
        dropdown.hidden = false;
    }

    async function fetchSuggestions(q) {
        try {
            const r = await fetch('/api/search?q=' + encodeURIComponent(q), {
                headers: { 'Accept': 'application/json' }
            });
            if (!r.ok) return;
            const data = await r.json();
            if (q !== lastQuery) return; // race-protection
            render(data.results || [], q);
        } catch (_) { /* silent */ }
    }

    input.addEventListener('input', () => {
        const q = input.value.trim();
        lastQuery = q;
        clearTimeout(timer);
        if (q.length < 2) {
            dropdown.hidden = true;
            dropdown.innerHTML = '';
            return;
        }
        timer = setTimeout(() => fetchSuggestions(q), 250);
    });

    input.addEventListener('focus', () => {
        if (input.value.trim().length >= 2 && dropdown.innerHTML) {
            dropdown.hidden = false;
        }
    });

    input.addEventListener('keydown', (e) => {
        const items = dropdown.querySelectorAll('.aa-search-item');
        if (e.key === 'Escape') {
            dropdown.hidden = true;
            return;
        }
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = (activeIndex + 1) % items.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = (activeIndex - 1 + items.length) % items.length;
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            window.location.href = items[activeIndex].getAttribute('href');
            return;
        } else {
            return;
        }
        items.forEach((el, i) => el.classList.toggle('is-active', i === activeIndex));
    });

    document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.hidden = true;
        }
    });
})();
