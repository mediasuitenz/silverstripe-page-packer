/**
 * Generic import preview widget: watches an UploadField for a completed upload and fills its
 * companion `.page-packer-import-preview[data-preview-url]` container with a summary (class/
 * title/asset count) via the same importPreview() endpoint the page-tree "Add new page" import
 * flow uses (see import-preview.js) — generalised via data attributes so it works for any number
 * of packer import modals on the same page (e.g. one per packable GridField) rather than a
 * single hardcoded #PagePackerImportPreview element tied to one upload field name.
 */
(function () {
    if (window.__pagePackerRecordImportPreviewReady) { return; }
    window.__pagePackerRecordImportPreviewReady = true;

    var lastSeenFileIds = new WeakMap();

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function renderPreview(container, meta) {
        var warning = meta.classExists ? '' : (
            '<p class="alert alert-warning page-packer-import-preview__warning">'
            + '&#8220;' + escapeHtml(meta.className) + '&#8221; is not a packable type installed on'
            + ' this site &mdash; the import may fail or partially apply, depending on the'
            + ' mismatch setting.</p>'
        );

        var assetCount = meta.assetCount || 0;

        container.innerHTML =
            '<table class="table table-sm table-bordered page-packer-import-preview__table">'
            + '<tbody>'
            + '<tr><th scope="row">Detected class</th><td>' + escapeHtml(meta.className) + '</td></tr>'
            + '<tr><th scope="row">Detected title</th><td>' + escapeHtml(meta.title || '—') + '</td></tr>'
            + '<tr><th scope="row">Assets attached</th><td>' + (assetCount > 0 ? assetCount : 'None') + '</td></tr>'
            + '</tbody>'
            + '</table>'
            + warning;
    }

    function renderError(container, message) {
        container.innerHTML = '<p class="alert alert-danger page-packer-import-preview__error">' + escapeHtml(message) + '</p>';
    }

    function fetchAndRenderPreview(container, fileId) {
        container.innerHTML = '<p class="page-packer-import-preview__loading">Checking file&hellip;</p>';

        var url = container.getAttribute('data-preview-url') + '?FileID=' + encodeURIComponent(fileId);

        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok || result.data.error) {
                    renderError(container, (result.data && result.data.error) || 'Could not read this file.');
                    return;
                }

                renderPreview(container, result.data);
            })
            .catch(function () {
                renderError(container, 'Could not read this file.');
            });
    }

    function checkContainer(container) {
        var fieldName = container.getAttribute('data-upload-field-name');
        var input = document.querySelector('input[name="' + fieldName + '[Files][]"]');
        var fileId = input ? input.value : null;
        var lastSeen = lastSeenFileIds.get(container) || null;

        if (fileId && fileId !== lastSeen) {
            lastSeenFileIds.set(container, fileId);
            fetchAndRenderPreview(container, fileId);
        } else if (!fileId && lastSeen) {
            lastSeenFileIds.delete(container);
            container.innerHTML = '';
        }
    }

    function checkAllContainers() {
        document.querySelectorAll('.page-packer-import-preview[data-preview-url][data-upload-field-name]')
            .forEach(checkContainer);
    }

    new MutationObserver(checkAllContainers).observe(document.body, { childList: true, subtree: true });

    setInterval(checkAllContainers, 500);
    checkAllContainers();
})();
