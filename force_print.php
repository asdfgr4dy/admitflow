<?php
$css = file_get_contents("c:/Users/Administrator/Documents/admitflow/public/assets/css/app.css");
// Replace the modal show rule with one that works regardless of Bootstrap's .show class
// This ensures the document always prints, even if Bootstrap's .show class isn't present
$old = ".document-preview-modal.show {
        display: block !important;
        position: static !important;
        overflow: visible !important;
    }";
$new = ".document-preview-modal {
        display: block !important;
        position: static !important;
        overflow: visible !important;
    }
    /* Also try if Bootstrap .show class is present */
    .document-preview-modal.show {
        display: block !important;
        position: static !important;
        overflow: visible !important;
    }";
$content = str_replace($old, $new, $css);
if ($content !== file_get_contents("c:/Users/Administrator/Documents/admitflow/public/assets/css/app.css")) {
    file_put_contents("c:/Users/Administrator/Documents/admitflow/public/assets/css/app.css", $content);
    echo "CSS updated\n";
} else {
    echo "No change needed\n";
}
?>