<?php

namespace MadeCurious\PagePacker\Support;

/**
 * Builds the "trigger button carrying its whole modal as a `data-modal` HTML string" markup
 * shared by every place this module pops up a small form in a modal — the Export trigger
 * (PackableExtension, used for both SiteTree and any other packable DataObject) and the
 * GridField Import trigger (GridFieldRecordImportButton). Pulled out purely to avoid three
 * copies of the same wrapper
 * markup; the modal open/close behaviour itself lives in client/dist/js/export-modal.js, which
 * is generic (keyed off `data-toggle="modal"`/`data-modal`, not anything export-specific).
 */
final class ModalMarkup
{
    public static function modal(string $modalId, string $title, string $bodyHtml): string
    {
        return '<div id="' . $modalId . '" class="modal fade" tabindex="-1" role="dialog">'
            . '<div class="modal-dialog" role="document"><div class="modal-content">'
            . '<div class="modal-header"><h2 class="modal-title">'
            . htmlspecialchars($title)
            . '</h2><button type="button" class="btn btn-close btn--icon-xl btn--no-text modal__close-button" '
            . 'data-dismiss="modal" aria-label="Close" title="Close">'
            . '<span class="btn__icon font-icon-cancel" aria-hidden="true"></span></button></div>'
            . '<div class="modal-body">' . $bodyHtml . '</div>'
            . '</div></div></div>';
    }

    public static function trigger(string $modalId, string $label, string $iconClass, string $modalHtml): string
    {
        return '<button type="button" class="btn btn-secondary ' . $iconClass . '" '
            . 'data-toggle="modal" data-target="#' . $modalId . '" '
            . 'data-modal="' . htmlspecialchars($modalHtml, ENT_QUOTES) . '">'
            . htmlspecialchars($label) . '</button>';
    }
}
