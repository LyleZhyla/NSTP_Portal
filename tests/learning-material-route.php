<?php
$source = file_get_contents(__DIR__ . '/../learning-management.php');
if ($source === false) throw new RuntimeException('Cannot read Learning Management.');
foreach (['start', 'chunk', 'finish', 'cancel', 'update_audience', 'set_availability', 'material_manage'] as $action) {
    if (!preg_match('/\$materialActions\s*=\s*\[[^\]]*[\'"]' . preg_quote($action, '/') . '[\'"]/s', $source)) {
        throw new RuntimeException("Missing page dispatch action: {$action}");
    }
}
if (strpos($source, 'name="operation" value="3"') === false) {
    throw new RuntimeException('Delete form must use the WAF-safe operation code.');
}
if (strpos($source, 'name="request_mode" value="form"') === false) {
    throw new RuntimeException('Delete must use a server-redirected form submission.');
}
$script = file_get_contents(__DIR__ . '/../include/learning-material-audience.js');
if (preg_match('/material-delete[\s\S]{0,1000}\bfetch\s*\(/', $script)) {
    throw new RuntimeException('Delete must not use an AJAX fetch.');
}
if (substr_count($source, 'action="?tab=learning-materials"') !== 4) {
    throw new RuntimeException('Every material mutation form must use the routable page endpoint.');
}
if (strpos($source, "require __DIR__ . '/endpoint/upload-learning-material.php';") === false) {
    throw new RuntimeException('Learning Management does not dispatch to the secured handler.');
}
echo "PASS material forms use the Learning Management route and dispatch every secured action\n";
