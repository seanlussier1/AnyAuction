// Create-listing form: live cross-field validation (reserve > starting,
// buy-now > starting, buy-now > reserve, at least one image) plus thumbnail
// previews for the photo input.
(function () {
    const form       = document.getElementById('sell-form');
    if (!form) return;

    const startingEl = document.getElementById('starting-price');
    const reserveEl  = document.getElementById('reserve-price');
    const buyNowEl   = document.getElementById('buy-now-price');
    const imgInput   = document.getElementById('image-upload');
    const reserveErr = document.getElementById('reserve-error');
    const buyNowErr  = document.getElementById('buy-now-error');
    const imgErr     = document.getElementById('images-error');

    function showErr(el, msg) {
        if (!el) return;
        el.textContent = msg;
        el.hidden = !msg;
    }

    function validate() {
        const s = parseFloat(startingEl.value);
        const r = parseFloat(reserveEl.value);
        const b = parseFloat(buyNowEl.value);
        let ok  = true;

        if (reserveEl.value !== '' && Number.isFinite(r)) {
            if (Number.isFinite(s) && r <= s) {
                showErr(reserveErr, 'Reserve must be greater than the starting price.');
                reserveEl.classList.add('is-invalid');
                ok = false;
            } else {
                showErr(reserveErr, '');
                reserveEl.classList.remove('is-invalid');
            }
        } else {
            showErr(reserveErr, '');
            reserveEl.classList.remove('is-invalid');
        }

        if (buyNowEl.value !== '' && Number.isFinite(b)) {
            if (Number.isFinite(s) && b <= s) {
                showErr(buyNowErr, 'Buy Now must be greater than the starting price.');
                buyNowEl.classList.add('is-invalid');
                ok = false;
            } else if (reserveEl.value !== '' && Number.isFinite(r) && b <= r) {
                showErr(buyNowErr, 'Buy Now must be greater than the reserve price.');
                buyNowEl.classList.add('is-invalid');
                ok = false;
            } else {
                showErr(buyNowErr, '');
                buyNowEl.classList.remove('is-invalid');
            }
        } else {
            showErr(buyNowErr, '');
            buyNowEl.classList.remove('is-invalid');
        }

        if (imgInput.files.length === 0) {
            showErr(imgErr, 'Please add at least one photo.');
            ok = false;
        } else {
            showErr(imgErr, '');
        }

        return ok;
    }

    [startingEl, reserveEl, buyNowEl].forEach(el => el.addEventListener('input', validate));
    imgInput.addEventListener('change', validate);

    form.addEventListener('submit', (e) => {
        if (!validate()) {
            e.preventDefault();
            const firstErr = form.querySelector('.text-danger.small:not([hidden])');
            if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    // Thumbnail previews
    imgInput.addEventListener('change', function(e) {
        const previews = document.getElementById('image-previews');
        if (!previews) return;
        previews.innerHTML = '';

        const files = Array.from(e.target.files);
        files.forEach((file) => {
            if (!file.type.startsWith('image/')) return;

            const reader = new FileReader();
            reader.onload = function(ev) {
                const previewDiv = document.createElement('div');
                previewDiv.className = 'position-relative';
                previewDiv.style.width  = '96px';
                previewDiv.style.height = '96px';

                const img = document.createElement('img');
                img.src = ev.target.result;
                img.className = 'img-thumbnail';
                img.style.width = '100%';
                img.style.height = '100%';
                img.style.objectFit = 'cover';

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-sm btn-danger position-absolute';
                removeBtn.style.top   = '2px';
                removeBtn.style.right = '2px';
                removeBtn.textContent = '×';
                removeBtn.onclick = function() { previewDiv.remove(); };

                previewDiv.appendChild(img);
                previewDiv.appendChild(removeBtn);
                previews.appendChild(previewDiv);
            };
            reader.readAsDataURL(file);
        });
    });
})();
