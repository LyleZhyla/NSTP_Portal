<?php
$source = file_get_contents(__DIR__ . '/../learning-management.php');
if ($source === false) throw new RuntimeException('Cannot read Learning Management.');
foreach (['start', 'chunk', 'finish', 'cancel', 'update_audience', 'set_availability', 'delete_material'] as $action) {
    if (!preg_match('/\$materialActions\s*=\s*\[[^\]]*[\'"]' . preg_quote($action, '/') . '[\'"]/s', $source)) {
        throw new RuntimeException("Missing page dispatch action: {$action}");
    }
}
if (substr_count($source, 'action="?tab=learning-materials"') !== 4) {
    throw new RuntimeException('Every material mutation form must use the routable page endpoint.');
}
if (strpos($source, "require __DIR__ . '/endpoint/upload-learning-material.php';") === false) {
    throw new RuntimeException('Learning Management does not dispatch to the secured handler.');
}
echo "PASS material forms use the Learning Management route and dispatch every secured action\n";
