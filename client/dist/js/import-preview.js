/**
 * Add-New-Page import preview: watches the PagePackerFile UploadField for a completed upload and
 * fills #PagePackerImportPreview with a summary table (class/title/slug/asset count) via importPreview().
 *
 */
(function () {
    if (window.__pagePackerImportPreviewReady) { return; }
    window.__pagePackerImportPreviewReady = true;

    var lastSeenFileId = null;

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function renderPreview(container, meta) {
        var warning = meta.classExists ? '' : (
            '<p class="alert alert-warning page-packer-import-preview__warning">'
            + '&#8220;' + escapeHtml(meta.className) + '&#8221; is not a page type installed on'
            + ' this site &mdash; the import may fail or partially apply, depending on the'
            + ' mismatch setting.</p>'
        );

        var assetCount = meta.assetCount || 0;

        container.innerHTML =
            '<table class="table table-sm table-bordered page-packer-import-preview__table">'
            + '<tbody>'
            + '<tr><th scope="row">Detected class</th><td>' + escapeHtml(meta.className) + '</td></tr>'
            + '<tr><th scope="row">Detected title</th><td>' + escapeHtml(meta.title || '—') + '</td></tr>'
            + '<tr><th scope="row">Detected slug</th><td>' + (meta.urlSegment ? '/' + escapeHtml(meta.urlSegment) : '—') + '</td></tr>'
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

    function checkForUploadedFile() {
        var container = document.getElementById('PagePackerImportPreview');

        if (!container) { return; }

        var input = document.querySelector('input[name="PagePackerFile[Files][]"]');
        var fileId = input ? input.value : null;

        if (fileId && fileId !== lastSeenFileId) {
            lastSeenFileId = fileId;
            fetchAndRenderPreview(container, fileId);
        } else if (!fileId && lastSeenFileId) {
            lastSeenFileId = null;
            container.innerHTML = '';
        }
    }

    new MutationObserver(checkForUploadedFile).observe(document.body, { childList: true, subtree: true });

    setInterval(checkForUploadedFile, 500);
    checkForUploadedFile();
})();
