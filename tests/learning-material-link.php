<?php
require_once __DIR__ . '/../include/learning-materials.php';

function checkMaterialLink($condition, $message) {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}

function rejectsMaterialLink($value) {
    try {
        normalizeLearningMaterialUrl($value);
        return false;
    } catch (InvalidArgumentException $error) {
        return true;
    }
}

checkMaterialLink(normalizeLearningMaterialUrl(' https://drive.google.com/file/d/example/view ') === 'https://drive.google.com/file/d/example/view', 'valid HTTPS link is normalized');
checkMaterialLink(rejectsMaterialLink('http://example.com/material'), 'HTTP link is rejected');
checkMaterialLink(rejectsMaterialLink('javascript:alert(1)'), 'script link is rejected');
checkMaterialLink(rejectsMaterialLink('https://user:pass@example.com/material'), 'link credentials are rejected');
checkMaterialLink(rejectsMaterialLink('not a link'), 'malformed link is rejected');

echo "PASS learning material links accept only safe HTTPS URLs\n";
