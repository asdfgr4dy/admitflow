<?php
// Debug: Check the modal structure and whether .show class is present
echo "Checking modal structure...\n";
// The modal in portal.php has: <div class="modal fade document-preview-modal">
// Bootstrap adds .show class when modal is opened via JavaScript
// If the modal was never opened via Bootstrap, .show class won't be present
echo "Expected: modal has 'show' class when opened by user clicking the print/document button\n";
echo "Issue: If modal was rendered in DOM but never opened via Bootstrap, .show class is missing\n";
echo "Fix: Ensure modal is properly opened, or use alternative selector approach\n";
?>