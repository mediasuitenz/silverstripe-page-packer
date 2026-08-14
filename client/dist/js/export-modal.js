/**
 * Controls the modal that fires when clicking "export" from the CMS actions bar
 *
 */
(function () {
    if (window.__pagePackerModalReady) { return; }
    window.__pagePackerModalReady = true;

    function closeModal(modalEl) {
        if (!modalEl) { return; }
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        modalEl.remove();
        document.body.classList.remove('modal-open');
        document.querySelectorAll('[data-page-packer-backdrop]').forEach(function (el) {
            el.remove();
        });
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-toggle="modal"][data-modal]');

        if (trigger) {
            e.preventDefault();
            e.stopPropagation();

            var existing = document.querySelector(trigger.getAttribute('data-target'));
            if (existing) { closeModal(existing); }

            var wrapper = document.createElement('div');
            wrapper.innerHTML = trigger.getAttribute('data-modal');
            var modalEl = wrapper.firstElementChild;
            document.body.appendChild(modalEl);

            var backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.setAttribute('data-page-packer-backdrop', '1');
            document.body.appendChild(backdrop);

            modalEl.classList.add('show');
            modalEl.style.display = 'block';
            document.body.classList.add('modal-open');

            return;
        }

        var dismiss = e.target.closest('[data-dismiss="modal"]');

        if (dismiss) {
            e.preventDefault();
            closeModal(dismiss.closest('.modal'));

            return;
        }

        if (e.target.classList && e.target.classList.contains('modal') && e.target.classList.contains('show')) {
            closeModal(e.target);
        }
    });

    var params = new URLSearchParams(window.location.search);
    var toastMessage = params.get('page-packer-toast');

    if (toastMessage) {
        var container = document.querySelector('.toasts');

        if (!container) {
            container = document.createElement('div');
            container.className = 'toasts';
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.className = 'toast toast--good';
        toast.innerHTML = '<div class="toast-header"><strong>Export</strong></div>'
            + '<div class="toast-body"></div>';
        toast.querySelector('.toast-body').textContent = toastMessage;
        container.appendChild(toast);

        setTimeout(function () {
            toast.remove();
        }, 6000);

        params.delete('page-packer-toast');

        var newSearch = params.toString();
        var newUrl = window.location.pathname + (newSearch ? '?' + newSearch : '') + window.location.hash;
        window.history.replaceState(null, '', newUrl);
    }
})();