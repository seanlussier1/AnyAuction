// Global heart toggle for any [data-aa-heart] button.
// POSTs to /watchlist/toggle/{id} and swaps the icon based on the response.
// Reads CSRF token from <meta name="csrf-token">; reads item id from
// data-aa-heart on the clicked button — never from a Twig interpolation,
// which keeps this file static and cacheable.
(function () {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrf     = csrfMeta ? csrfMeta.getAttribute('content') : '';

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-aa-heart]');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();

        const itemId = btn.getAttribute('data-aa-heart');
        if (!itemId) return;

        const fd = new FormData();
        fd.append('_csrf', csrf);

        try {
            const r = await fetch('/watchlist/toggle/' + encodeURIComponent(itemId), {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: fd
            });
            if (r.status === 401) {
                window.location.href = '/login';
                return;
            }
            if (!r.ok) return;
            const data = await r.json();
            const icon = btn.querySelector('i');
            if (data.watching) {
                icon.classList.remove('bi-heart');
                icon.classList.add('bi-heart-fill', 'text-danger');
                btn.classList.add('is-active');
                btn.setAttribute('aria-pressed', 'true');
                btn.setAttribute('aria-label', 'Remove from watchlist');
            } else {
                icon.classList.remove('bi-heart-fill', 'text-danger');
                icon.classList.add('bi-heart');
                btn.classList.remove('is-active');
                btn.setAttribute('aria-pressed', 'false');
                btn.setAttribute('aria-label', 'Add to watchlist');
            }
        } catch (_) { /* silent */ }
    });
})();
