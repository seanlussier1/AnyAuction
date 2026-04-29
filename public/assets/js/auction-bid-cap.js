// On the auction-show page, cap the bid input at the buyout price (read
// from the input's `max` attribute, which Twig sets server-side). When the
// user types over the cap, rewrite the input and flash the hint.
(function () {
    const input = document.getElementById('aa-bid-amount');
    const hint  = document.getElementById('aa-bid-cap-hint');
    if (!input) return;

    const max = parseFloat(input.getAttribute('max'));
    if (!Number.isFinite(max)) return;

    let hideTimer = null;
    function showHint() {
        if (!hint) return;
        hint.hidden = false;
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => { hint.hidden = true; }, 3000);
    }

    function cap() {
        const v = parseFloat(input.value);
        if (Number.isFinite(v) && v > max) {
            input.value = max.toFixed(2);
            showHint();
        }
    }

    input.addEventListener('input', cap);
    input.addEventListener('change', cap);
})();
