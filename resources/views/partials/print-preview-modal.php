<div class="modal fade print-preview-modal" id="printPreviewModal" tabindex="-1" aria-labelledby="printPreviewModalTitle" aria-hidden="true" data-print-preview-modal data-preview-kind="<?= !empty($studentDocumentPreviewModal) ? 'student' : 'report' ?>">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header print-preview-toolbar">
                <h2 class="modal-title fs-5" id="printPreviewModalTitle">Print preview</h2>
                <?php if (empty($studentDocumentPreviewModal)): ?><div class="btn-group print-preview-orientation" role="group" aria-label="Page orientation">
                    <button type="button" class="btn btn-outline-navy" data-preview-orientation="portrait" aria-pressed="false">Portrait</button>
                    <button type="button" class="btn btn-outline-navy active" data-preview-orientation="landscape" aria-pressed="true">Landscape</button>
                </div><?php endif; ?>
                <?php if (!empty($reportPreviewModal)): ?><label class="print-preview-signature-toggle"><input type="checkbox" data-preview-signatures> <span>Include signature lines</span></label><?php endif; ?>
                <div class="print-preview-toolbar-actions">
                    <button type="button" class="btn btn-amber" data-preview-print disabled><i class="bi bi-printer" aria-hidden="true"></i> Print</button>
                    <button type="button" class="btn btn-outline-navy" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
            <?php if (!empty($reportPreviewModal)): ?><p class="print-preview-tip">In the print dialog, turn off 'Headers and footers' for a clean report.</p><?php elseif (!empty($studentDocumentPreviewModal)): ?><p class="print-preview-tip">In the print dialog, turn off 'Headers and footers' for a clean copy.</p><?php endif; ?>
            <div class="modal-body print-preview-modal-body">
                <div class="print-preview-loading" role="status" aria-live="polite" data-preview-loading><span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Loading print preview…</span></div>
                <div class="print-preview-error" role="alert" data-preview-error hidden><p>The print preview could not be loaded.</p><button type="button" class="btn btn-outline-navy btn-sm" data-preview-retry>Retry</button></div>
                <iframe class="print-preview-frame" title="Printable document preview" data-preview-frame hidden></iframe>
            </div>
        </div>
    </div>
</div>