// Polls /api/heartbeat to detect new deploys and listing activity.
// Shows a non-intrusive banner — never force-refreshes mid-action. Banner
// state is held in sessionStorage so it survives client-side navigation.
//
// Self-clearing rules:
//   - Version banner clears when the navigated-to page's meta app-version
//     matches the version that was being advertised. That means the
//     navigation effectively was the refresh.
//   - Listings banner clears when the user lands on a listings page
//     (`/`, `/browse`) — a fresh page load there shows the latest items,
//     so the "new activity" notice has been consumed.
(() => {
    const meta = document.querySelector('meta[name="app-version"]');
    const pageVersion = meta?.getAttribute('content') || 'dev';

    const listingsPages = ['/', '/browse'];
    const onListingsPage = listingsPages.includes(location.pathname);

    const VERSION_KEY  = 'aa_hb_version_pending';
    const LISTINGS_KEY = 'aa_hb_listings_pending';
    const POLL_MS      = 60_000;

    let initialListings = null;
    let banner = null;
    let pollHandle = null;

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
        banner.querySelector('.aa-hb-refresh').addEventListener('click', () => {
            // Refresh acts on whichever banner is showing — wipe both keys
            // so the reload doesn't immediately re-render the banner if
            // the new HTML is already up-to-date.
            sessionStorage.removeItem(VERSION_KEY);
            sessionStorage.removeItem(LISTINGS_KEY);
            location.reload();
        });
        banner.querySelector('.aa-hb-close').addEventListener('click', () => {
            sessionStorage.removeItem(VERSION_KEY);
            sessionStorage.removeItem(LISTINGS_KEY);
            banner?.remove();
            banner = null;
        });
        document.body.appendChild(banner);
        return banner;
    };

    const showBanner = (text, refreshLabel) => {
        const el = buildBanner(text, refreshLabel);
        const slot = el.querySelector('.aa-hb-text');
        if (slot.textContent !== text) slot.textContent = text;
    };

    // === On every page load, replay any pending notification from session
    // storage so the banner survives navigation. Self-clear when the new
    // page makes the banner irrelevant.
    const pendingVersion  = sessionStorage.getItem(VERSION_KEY);
    const pendingListings = sessionStorage.getItem(LISTINGS_KEY);

    if (pendingVersion) {
        if (pendingVersion === pageVersion) {
            // The page we just loaded *is* the advertised version — the
            // navigation served as the refresh.
            sessionStorage.removeItem(VERSION_KEY);
        } else {
            showBanner('A new version of AnyAuction is available.', 'Refresh');
        }
    }

    if (pendingListings && !pendingVersion) {
        // Version banner takes priority (it implies a hard refresh anyway),
        // so only render the listings banner when there's no version one.
        if (onListingsPage) {
            // Fresh /browse or / load — the listings update has been
            // consumed by reloading.
            sessionStorage.removeItem(LISTINGS_KEY);
        } else {
            showBanner('New listings or bids since you opened the site.', 'Refresh');
        }
    }

    const tick = async () => {
        try {
            const res = await fetch('/api/heartbeat', {
                cache: 'no-store',
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const data = await res.json();

            if (initialListings === null) {
                initialListings = data.listings_changed ?? 0;
            }

            const versionChanged = data.version && data.version !== pageVersion;
            const listingsChanged = data.listings_changed
                && data.listings_changed > initialListings;

            // Version banner takes priority — once tripped we stop polling.
            if (versionChanged) {
                sessionStorage.setItem(VERSION_KEY, data.version);
                showBanner('A new version of AnyAuction is available.', 'Refresh');
                stop();
                return;
            }
            if (listingsChanged) {
                sessionStorage.setItem(LISTINGS_KEY, String(data.listings_changed));
                if (onListingsPage || !banner) {
                    // On a listings page → user can immediately benefit
                    // from refreshing. Off listings → still surface so
                    // they know to head back.
                    showBanner('New listings or bids since you opened this page.', 'Refresh');
                }
            }
        } catch {
            // Network blip — try again on the next tick.
        }
    };

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

    // Skip polling on hidden tabs to save battery + server load. Resume on
    // focus so a banner can fire promptly when the user comes back.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stop(); else start();
    });

    if (!document.hidden) start();
})();
