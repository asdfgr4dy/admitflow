<?php
$css = file_get_contents("c:/Users/Administrator/Documents/admitflow/public/assets/css/app.css");
// Check the .document-preview-modal.show rule
if (strpos($css, ".document-preview-modal.show") !== false) {
    echo "Found .document-preview-modal.show\n";
    // Extract the rule
    $start = strpos($css, ".document-preview-modal.show");
    $end = strpos($css, "}", $start + 50);
    if ($end === false) $end = $start + 500;
    $rule = substr($css, $start, $end - $start + 1);
    echo "Rule: " . $rule . "\n";
} else {
    echo "NOT FOUND\n";
}
?>