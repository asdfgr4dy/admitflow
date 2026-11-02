<?php
$drawerConfig = $drawerConfig ?? [];
$drawerId = (string) ($drawerConfig['id'] ?? 'recordDrawer');
$drawerTitleId = (string) ($drawerConfig['title_id'] ?? $drawerId . 'Title');
$drawerRecordLabel = (string) ($drawerConfig['record_label'] ?? 'record');
$drawerHeaderLabel = (string) ($drawerConfig['header_label'] ?? 'Record details');
$drawerTitle = (string) ($drawerConfig['title'] ?? 'Add ' . $drawerRecordLabel);
$drawerSubtitle = (string) ($drawerConfig['subtitle'] ?? 'Enter the record details below.');
$drawerAction = (string) ($drawerConfig['action'] ?? '');
$drawerOpen = !empty($drawerConfig['open']);
$drawerEditing = !empty($drawerConfig['editing']);
$drawerNameField = (string) ($drawerConfig['id_field'] ?? '');
$drawerRecordId = (int) ($drawerConfig['record_id'] ?? 0);
$drawerSaveLabel = (string) ($drawerConfig['save_label'] ?? ($drawerEditing ? 'Save changes' : 'Save'));
$drawerAddLabel = (string) ($drawerConfig['add_label'] ?? $drawerSaveLabel);
$drawerEditLabel = (string) ($drawerConfig['edit_label'] ?? $drawerSaveLabel);
$drawerCancelLabel = (string) ($drawerConfig['cancel_label'] ?? 'Cancel');
$drawerWidth = (string) ($drawerConfig['width'] ?? '');
$drawerReadOnly = !empty($drawerConfig['read_only']);
$drawerAnnouncement = !empty($drawerConfig['announcement']);
$drawerConfirmEdit = (string) ($drawerConfig['confirm_edit'] ?? '');
?>
<div class="offcanvas offcanvas-end admitflow-form-drawer <?= e((string) ($drawerConfig['class'] ?? '')) ?>" tabindex="-1" id="<?= e($drawerId) ?>" aria-labelledby="<?= e($drawerTitleId) ?>" data-form-drawer data-record-label="<?= e($drawerRecordLabel) ?>" data-open="<?= $drawerOpen ? 'true' : 'false' ?>" data-editing="<?= $drawerEditing ? 'true' : 'false' ?>" <?= $drawerWidth !== '' ? 'style="--bs-offcanvas-width:' . e($drawerWidth) . '"' : '' ?>>
    <?php if (!$drawerReadOnly): ?><form method="post" action="<?= e($drawerAction) ?>" data-submit-once data-drawer-form <?= $drawerAnnouncement ? 'data-announcement-form' : '' ?> <?= $drawerConfirmEdit !== '' ? 'data-confirm-edit="' . e($drawerConfirmEdit) . '"' : '' ?>>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <?php if ($drawerNameField !== ''): ?><input type="hidden" name="<?= e($drawerNameField) ?>" value="<?= $drawerRecordId ?>" data-drawer-id><?php endif; ?>
    <?php endif; ?>
        <div class="offcanvas-header form-drawer-header"><div><p class="eyebrow"><?= e($drawerHeaderLabel) ?></p><h2 id="<?= e($drawerTitleId) ?>" data-drawer-title><?= e($drawerTitle) ?></h2><p data-drawer-subtitle><?= e($drawerSubtitle) ?></p></div><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close drawer"></button></div>
        <div class="offcanvas-body form-drawer-body" data-drawer-body><?= $drawerContent ?? '' ?></div>
        <div class="form-drawer-footer"><button type="button" class="btn btn-outline-navy" data-bs-dismiss="offcanvas"><?= e($drawerCancelLabel) ?></button><?php if (!$drawerReadOnly): ?><button type="submit" class="btn btn-amber" data-drawer-save data-add-label="<?= e($drawerAddLabel) ?>" data-edit-label="<?= e($drawerEditLabel) ?>" data-loading-label="Saving..." <?= $drawerEditing ? 'disabled' : '' ?>><span data-announcement-submit-label><?= e($drawerSaveLabel) ?></span></button><?php endif; ?></div>
    <?php if (!$drawerReadOnly): ?></form><?php endif; ?>
</div>