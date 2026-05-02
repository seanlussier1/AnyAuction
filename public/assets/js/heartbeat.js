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
    const POLL_MS      = 15_000;

    let initialListings = null;
    let banner = null;
    let pollHandle = null;
    // Track the highest notification id we've already rendered so the
    // poller can request only the delta on subsequent ticks. Seeded from
    // the server-rendered list on page load (data-attribute set by the
    // Notifications tab pane when it has rows).
    let lastSeenNotifId = (() => {
        const list = document.querySelectorAll('#tab-notifications [data-aa-notif-id]');
        let max = 0;
        list.forEach((el) => {
            const id = parseInt(el.getAttribute('data-aa-notif-id') || '0', 10);
            if (id > max) max = id;
        });
        return max;
    })();

    // Same idea for live listing-feed updates. The /browse grid is marked
    // with [data-aa-listings-grid] and each rendered card carries
    // [data-aa-listing-id]. We use the max as the high-water mark and
    // request /api/listings/recent?since=<that> on each tick.
    const listingsGrid = document.querySelector('[data-aa-listings-grid]');
    let lastSeenListingId = 0;
    if (listingsGrid) {
        listingsGrid.querySelectorAll('[data-aa-listing-id]').forEach((el) => {
            const id = parseInt(el.getAttribute('data-aa-listing-id') || '0', 10);
            if (id > lastSeenListingId) lastSeenListingId = id;
        });
    }

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

            // Live notification updates — bell badge + (when on the
            // notifications tab) prepend any new rows.
            applyUnreadCount(data.unread_notifications ?? 0);
            const latestId = data.latest_notification_id ?? 0;
            if (latestId > lastSeenNotifId) {
                await fetchAndPrependNotifications();
            }

            // Live listing prepend on listing-grid pages (/browse).
            const latestListing = data.latest_listing_id ?? 0;
            if (listingsGrid && latestListing > lastSeenListingId) {
                await fetchAndPrependListings();
            }
        } catch {
            // Network blip — try again on the next tick.
        }
    };

    const applyUnreadCount = (count) => {
        // Navbar bell pill
        const bell = document.querySelector('a[aria-label="Notifications"]');
        if (bell) {
            let pill = bell.querySelector('.aa-bell-badge');
            if (count > 0) {
                if (!pill) {
                    pill = document.createElement('span');
                    pill.className = 'aa-bell-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark';
                    pill.style.cssText = 'font-size: 0.65rem; padding: 0.25rem 0.4rem;';
                    bell.appendChild(pill);
                }
                pill.textContent = count > 9 ? '9+' : String(count);
            } else if (pill) {
                pill.remove();
            }
        }
        // Profile-tab label badge
        const tabBtn = document.getElementById('aa-notifs-tab');
        if (tabBtn) {
            let badge = tabBtn.querySelector('.badge');
            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'badge bg-warning text-dark ms-1';
                    tabBtn.appendChild(badge);
                }
                badge.textContent = String(count);
            } else if (badge) {
                badge.remove();
            }
        }
    };

    const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));

    const iconForType = (type) => {
        const t = String(type || '');
        if (t === 'outbid')          return { icon: 'bi-arrow-up-circle',     tone: 'warning'   };
        if (t === 'auction_won')     return { icon: 'bi-trophy-fill',         tone: 'success'   };
        if (t === 'auction_lost')   return { icon: 'bi-x-circle',             tone: 'secondary' };
        if (t === 'snipe_extended') return { icon: 'bi-stopwatch',            tone: 'info'      };
        if (t === 'bid_received')   return { icon: 'bi-cash-coin',            tone: 'info'      };
        if (t === 'item_sold')      return { icon: 'bi-check-circle-fill',    tone: 'success'   };
        if (t === 'order_paid')     return { icon: 'bi-credit-card-2-front', tone: 'success'   };
        if (t === 'welcome')         return { icon: 'bi-stars',                tone: 'warning'   };
        if (t.indexOf('watchlist_') === 0) return { icon: 'bi-heart',         tone: 'danger'    };
        return { icon: 'bi-bell', tone: 'secondary' };
    };

    const renderNotificationRow = (n) => {
        const { icon, tone } = iconForType(n.type);
        const href = n.href || '#';
        const created = n.created_at
            ? new Date(n.created_at.replace(' ', 'T') + 'Z').toLocaleString()
            : '';
        const newPill = n.is_read == 0 ? '<span class="badge bg-warning text-dark">New</span>' : '';
        const bodyHtml = n.body ? `<div class="small text-muted-aa">${escapeHtml(n.body)}</div>` : '';
        const wrapClass = 'd-flex align-items-start gap-3 p-3 rounded-lg-aa border text-decoration-none text-dark'
            + (n.is_read == 0 ? ' bg-warning-subtle' : '');
        return `
            <a href="${escapeHtml(href)}" data-aa-notif-id="${parseInt(n.notification_id, 10)}" class="${wrapClass}">
                <span class="aa-brand-badge bg-${tone}-subtle text-${tone}-emphasis d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px; flex-shrink: 0;">
                    <i class="bi ${icon} fs-5"></i>
                </span>
                <div class="flex-grow-1 min-w-0">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="fw-medium">${escapeHtml(n.title)}</div>
                        ${newPill}
                    </div>
                    ${bodyHtml}
                    <div class="small text-muted-aa opacity-75">${escapeHtml(created)}</div>
                </div>
            </a>
        `;
    };

    const formatPrice = (n) => {
        const v = Number(n) || 0;
        return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    const formatPriceCompact = (n) => Math.round(Number(n) || 0).toLocaleString('en-US');

    const renderListingCard = (a) => {
        const href = `/auction/${a.item_id}`;
        const titleSafe = escapeHtml(a.title);
        const imgPart = a.primary_image
            ? `<img src="${escapeHtml(a.primary_image)}" alt="${titleSafe}" loading="lazy">`
            : `<div class="d-flex align-items-center justify-content-center h-100 text-muted-aa"><i class="bi bi-image fs-1"></i></div>`;
        const featuredBadge = a.featured ? '<span class="aa-badge aa-badge-featured">Featured</span>' : '';
        const buyNowBadge = a.buy_now_price
            ? `<span class="aa-badge aa-badge-buynow">Buy Now $${formatPriceCompact(a.buy_now_price)}</span>`
            : '';
        const reserveBadge = (a.reserve_price && a.current_price < a.reserve_price)
            ? '<span class="badge bg-warning-subtle text-warning-emphasis align-self-start">Reserve not met</span>'
            : '';
        const bidsLabel = `${a.total_bids} bid${a.total_bids === 1 ? '' : 's'}`;

        return `
            <div class="aa-card">
                <a href="${href}" class="aa-card-img d-block text-decoration-none">
                    ${imgPart}
                    ${featuredBadge}
                    ${buyNowBadge}
                    <button type="button"
                            class="aa-heart"
                            aria-label="Add to watchlist"
                            aria-pressed="false"
                            data-aa-heart="${a.item_id}">
                        <i class="bi bi-heart"></i>
                    </button>
                </a>
                <div class="aa-card-body d-flex flex-column gap-2 flex-grow-1">
                    <a href="${href}" class="text-decoration-none text-dark">
                        <h3 class="h6 line-clamp-2 mb-0">${titleSafe}</h3>
                    </a>
                    <div class="d-flex align-items-end justify-content-between">
                        <div>
                            <div class="small text-muted-aa">Current Bid</div>
                            <div class="fs-5 text-emerald">$${formatPrice(a.current_price)}</div>
                        </div>
                        <div class="text-end small text-muted-aa">
                            <div>${bidsLabel}</div>
                            <div><i class="bi bi-clock me-1"></i>${escapeHtml(a.time_left)}</div>
                        </div>
                    </div>
                    ${reserveBadge}
                </div>
            </div>
        `;
    };

    const fetchAndPrependListings = async () => {
        if (!listingsGrid) return;
        try {
            const params = new URLSearchParams({ since: String(lastSeenListingId) });
            const cat = listingsGrid.getAttribute('data-aa-category-id') || '';
            if (cat) params.set('category', cat);

            const res = await fetch('/api/listings/recent?' + params.toString(), {
                cache: 'no-store',
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const data = await res.json();
            const fresh = (data.listings || []).filter((a) => a.item_id > lastSeenListingId);
            if (fresh.length === 0) return;

            // Newest first — server returns DESC, so prepending each in
            // turn keeps that order at the top of the grid.
            fresh.forEach((a) => {
                if (a.item_id > lastSeenListingId) lastSeenListingId = a.item_id;
                const wrap = document.createElement('div');
                wrap.className = 'col';
                wrap.setAttribute('data-aa-listing-id', String(a.item_id));
                wrap.innerHTML = renderListingCard(a);
                listingsGrid.insertBefore(wrap, listingsGrid.firstChild);
            });

            // If the page had previously rendered "No auctions found" empty
            // state, hide it now that we have results.
            const empty = document.querySelector('.text-center.py-5 a.btn-primary[href="/browse"]');
            if (empty) empty.closest('.text-center.py-5')?.remove();
        } catch {
            // Network blip — try again on the next tick.
        }
    };

    const fetchAndPrependNotifications = async () => {
        try {
            const res = await fetch(`/api/notifications/recent?since=${lastSeenNotifId}`, {
                cache: 'no-store',
                credentials: 'same-origin',
            });
            if (!res.ok) return;
            const data = await res.json();
            const fresh = (data.notifications || []).filter(
                (n) => parseInt(n.notification_id, 10) > lastSeenNotifId
            );
            if (fresh.length === 0) return;

            // Update the high-water mark so the next tick is a true delta.
            fresh.forEach((n) => {
                const id = parseInt(n.notification_id, 10);
                if (id > lastSeenNotifId) lastSeenNotifId = id;
            });

            // Find or build the list container inside the Notifications tab.
            const tab = document.querySelector('#tab-notifications');
            if (!tab) return;

            let list = tab.querySelector('.d-flex.flex-column.gap-2');
            if (!list) {
                // Empty-state was rendered server-side — replace it with a list.
                tab.querySelectorAll('.text-center.py-5.text-muted-aa').forEach((el) => el.remove());
                list = document.createElement('div');
                list.className = 'd-flex flex-column gap-2';
                tab.appendChild(list);
            }

            // Newest at the top — fresh comes back DESC, so prepend in order.
            fresh.forEach((n) => {
                const wrapper = document.createElement('div');
                wrapper.innerHTML = renderNotificationRow(n).trim();
                const node = wrapper.firstElementChild;
                if (node) list.insertBefore(node, list.firstChild);
            });
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
