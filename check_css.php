<?php
$css = file_get_contents("c:/Users/Administrator/Documents/admitflow/public/assets/css/app.css");
$checks = [
    ".document-preview-modal",
    ".document-preview-modal.show",
    ".modal-body",
    ".document-print-page",
    ".document-print-header",
    ".document-print-section",
    "@media print",
];
foreach ($checks as $check) {
    echo $check . ": " . (strpos($css, $check) !== false ? "PRESENT" : "MISSING") . "\n";
}
?>