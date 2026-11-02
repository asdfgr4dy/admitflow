<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Applications report</title>
<link rel="stylesheet" href="<?= e($stylesheetHref) ?>">
</head>
<body class="print-preview-document report-print-document report-pdf-document<?= $includeSignatureLines ? ' include-signature-lines' : '' ?>">
<?php require __DIR__ . '/reports-print.php'; ?>
</body>
</html>
