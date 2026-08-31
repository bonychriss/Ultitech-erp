(function () {
    function resolveToggleUrl(btn) {
        if (btn && btn.getAttribute('data-toggle-url')) {
            return btn.getAttribute('data-toggle-url');
        }
        if (typeof window.PV_REFERENCE_TOGGLE_URL === 'string' && window.PV_REFERENCE_TOGGLE_URL !== '') {
            return window.PV_REFERENCE_TOGGLE_URL;
        }
        return '/toggle-voucher-reference.php';
    }

    function updateStarButton(btn, isMarked) {
        var icon = btn.querySelector('i');
        var title = isMarked ? 'Reference voucher (click to unmark)' : 'Mark as reference';
        btn.classList.toggle('is-marked', isMarked);
        btn.setAttribute('data-is-reference', isMarked ? '1' : '0');
        btn.setAttribute('title', title);
        btn.setAttribute('aria-label', title);
        btn.setAttribute('aria-pressed', isMarked ? 'true' : 'false');
        if (icon) {
            icon.classList.remove('fas', 'far');
            icon.classList.add(isMarked ? 'fas' : 'far', 'fa-star');
        }
    }

    function toggleStar(btn) {
        if (!btn || btn.disabled) {
            return;
        }

        var voucherId = btn.getAttribute('data-voucher-id');
        if (!voucherId) {
            return;
        }

        var wasMarked = btn.getAttribute('data-is-reference') === '1';
        updateStarButton(btn, !wasMarked);
        btn.disabled = true;

        fetch(resolveToggleUrl(btn), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'voucher_id=' + encodeURIComponent(voucherId)
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, error: 'Invalid response from server.' };
                });
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    updateStarButton(btn, wasMarked);
                    var msg = (data && data.error) ? data.error : 'Could not update reference mark.';
                    if (typeof Swal !== 'undefined' && Swal.fire) {
                        Swal.fire({ icon: 'error', title: 'Reference', text: msg, timer: 2500, showConfirmButton: false });
                    } else {
                        alert(msg);
                    }
                    return;
                }
                updateStarButton(btn, parseInt(String(data.is_reference || '0'), 10) === 1);
            })
            .catch(function () {
                updateStarButton(btn, wasMarked);
                if (typeof Swal !== 'undefined' && Swal.fire) {
                    Swal.fire({ icon: 'error', title: 'Reference', text: 'Could not update reference mark.', timer: 2500, showConfirmButton: false });
                } else {
                    alert('Could not update reference mark.');
                }
            })
            .finally(function () {
                btn.disabled = false;
            });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.pv-reference-star-btn');
        if (!btn) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        toggleStar(btn);
    }, true);
})();
