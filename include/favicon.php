<?php
// Save as include/favicon.php
header('Content-Type: image/x-icon');

// Create a simple favicon
$image = imagecreate(32, 32);
$bg = imagecolorallocate($image, 44, 62, 80); // Dark blue-gray
$text_color = imagecolorallocate($image, 255, 255, 255); // White

// Add text "N" for NSTP
imagestring($image, 3, 8, 8, 'N', $text_color);

// Output as ico
imagepng($image);
imagedestroy($image);
?>