<?php
require_once __DIR__ . '/../include/learning-materials.php';

function checkVideo($condition, $label) {
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}
function rejectsVideo(callable $call) {
    try { $call(); } catch (InvalidArgumentException $error) { return true; }
    return false;
}

// Small container headers exercise fileinfo validation, not codec decoding.
$samples = [
    'lesson.mp4' => pack('N', 24) . 'ftypisom' . pack('N', 512) . 'isommp42',
    'lesson.MOV' => pack('N', 20) . 'ftypqt  ' . pack('N', 0) . 'qt  ',
    'lesson.webm' => hex2bin('1a45dfa39f4286810142f7810142f2810442f381084282847765626d4287810242858102'),
];
$path = tempnam(sys_get_temp_dir(), 'material-video-');
try {
    foreach ($samples as $name => $sample) {
        file_put_contents($path, learningMaterialFileGuard() . $sample);
        $stream = fopen($path, 'r+b');
        try {
            checkVideo(validateLearningMaterialFile($path, $name, learningMaterialUploadLimit(), strlen(learningMaterialFileGuard()), $stream, true) === strlen($sample), "$name accepted after protected-storage guard");
        } finally { fclose($stream); }
        checkVideo(rejectsVideo(fn() => validateLearningMaterialFile($path, $name, 1000, strlen(learningMaterialFileGuard()))), "$name does not expand quiz attachment types");
        checkVideo(rejectsVideo(fn() => validateLearningMaterialFile($path, $name, 1, strlen(learningMaterialFileGuard()), null, true)), "$name respects upload size limit");
    }
    file_put_contents($path, '<html>Not a video</html>');
    foreach (array_keys($samples) as $name) {
        checkVideo(rejectsVideo(fn() => validateLearningMaterialFile($path, $name, 1000, 0, null, true)), "$name rejects disguised text/HTML");
    }
    checkVideo(learningMaterialVideoMime('lesson.MP4') === 'video/mp4' && learningMaterialVideoMime('lesson.pdf') === null, 'inline playback only recognizes video extensions');
} finally { unlink($path); }
