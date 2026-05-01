// Polls /api/heartbeat to detect new deploys and listing activity.
// Shows a non-intrusive banner — never force-refreshes mid-action.
(() => {
    const meta = document.querySelector('meta[name="app-version"]');
    const initialVersion = meta?.getAttribute('content') || 'dev';

    // Pages where new listings/bids are visible — nudge user to refresh
    // when they change. Other pages only watch the deploy version.
    const listingsPages = ['/', '/browse'];
    const watchListings = listingsPages.includes(location.pathname);

    let initialListings = null;
    let banner = null;
    let pollHandle = null;

    const POLL_MS = 60_000;

    const buildBanner = (text, refreshLabel = 'Refresh') => {
        if (banner) return banner;
        banner = document.createElement('div');
        banner.id = 'aa-heartbeat-banner';
        banner.setAttribute('role', 'status');
        banner.style.cssText = [
            'position:fixed', 'left:50%', 'bottom:1rem',
            'transform:translateX(-50%)', 'z-index:1080',
            'background:#1a1a2e', 'color:#fff',
            'padding:.6rem 1rem', 'border-radius:999px',
            'box-shadow:0 8px 24px rgba(0,0,0,.25)',
            'display:flex', 'gap:.75rem', 'align-items:center',
            'font-size:.9rem', 'max-width:calc(100% - 2rem)',
        ].join(';');
        banner.innerHTML = `
            <span class="aa-hb-text"></span>
            <button type="button" class="aa-hb-refresh"
                    style="background:#f5b800;color:#1a1a2e;border:0;border-radius:999px;padding:.25rem .75rem;font-weight:600;cursor:pointer;">
                ${refreshLabel}
            </button>
            <button type="button" class="aa-hb-close" aria-label="Dismiss"
                    style="background:transparent;color:#fff;border:0;font-size:1.25rem;line-height:1;cursor:pointer;opacity:.7;">×</button>
        `;
        banner.querySelector('.aa-hb-refresh').addEventListener('click', () => location.reload());
        banner.querySelector('.aa-hb-close').addEventListener('click', () => banner?.remove());
        document.body.appendChild(banner);
        return banner;
    };

    const showBanner = (text, refreshLabel) => {
        const el = buildBanner(text, refreshLabel);
        const slot = el.querySelector('.aa-hb-text');
        if (slot.textContent !== text) slot.textContent = text;
    };

    const tick = async () => {
        try {
            const res = await fetch('/api/heartbeat', {
                cache: 'no-store',
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const data = await res.json();

            // Capture the listings baseline on the first successful poll
            // so we don't show a "new listings" banner just for the values
            // already on screen.
            if (initialListings === null) {
                initialListings = data.listings_changed ?? 0;
            }

            const versionChanged = data.version && data.version !== initialVersion;
            const listingsChanged = watchListings
                && data.listings_changed
                && data.listings_changed > initialListings;

            // Deploy banner takes priority — it implies a hard refresh anyway.
            if (versionChanged) {
                showBanner('A new version of AnyAuction is available.', 'Refresh');
                clearInterval(pollHandle);
                return;
            }
            if (listingsChanged) {
                showBanner('New listings or bids since you opened this page.', 'Refresh');
            }
        } catch {
            // Network blip — try again on the next tick.
        }
    };

    // Don't fire on tabs the user isn't looking at — saves battery and
    // server load. Resume immediately when the tab comes back to focus.
    const start = () => {
        if (pollHandle) return;
        tick();
        pollHandle = setInterval(tick, POLL_MS);
    };
    const stop = () => {
        if (!pollHandle) return;
        clearInterval(pollHandle);
        pollHandle = null;
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stop(); else start();
    });

    if (!document.hidden) start();
})();
