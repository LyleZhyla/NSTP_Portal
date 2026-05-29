<?php
// Save as include/logo.php
header('Content-Type: image/png');

// Create image
$width = 200;
$height = 200;
$image = imagecreatetruecolor($width, $height);

// Colors - Matte color scheme
$bg_color = imagecolorallocate($image, 44, 62, 80); // Dark blue-gray (#2c3e50)
$text_color = imagecolorallocate($image, 255, 255, 255); // White
$accent_color = imagecolorallocate($image, 52, 152, 219); // Light blue (#3498db)
$border_color = imagecolorallocate($image, 41, 128, 185); // Darker blue

// Fill background
imagefilledrectangle($image, 0, 0, $width, $height, $bg_color);

// Add gradient effect (simple stripes)
for ($i = 0; $i < $width; $i += 10) {
    imageline($image, $i, 0, $i, $height, $accent_color);
}

// Add border
imagerectangle($image, 2, 2, $width-3, $height-3, $border_color);
imagerectangle($image, 1, 1, $width-2, $height-2, $accent_color);

// Add text
$text = "NSTP";
$font_size = 5; // Built-in font size
$text_width = imagefontwidth($font_size) * strlen($text);
$x = ($width - $text_width) / 2;
imagestring($image, $font_size, $x, 40, $text, $text_color);

$text = "CUTS";
$text_width = imagefontwidth($font_size) * strlen($text);
$x = ($width - $text_width) / 2;
imagestring($image, $font_size, $x, 80, $text, $text_color);

$text = "ROTC";
$text_width = imagefontwidth($font_size) * strlen($text);
$x = ($width - $text_width) / 2;
imagestring($image, $font_size, $x, 120, $text, $text_color);

$text = "LTS";
$text_width = imagefontwidth($font_size) * strlen($text);
$x = ($width - $text_width) / 2;
imagestring($image, $font_size, $x, 160, $text, $text_color);

// Output image
imagepng($image);
imagedestroy($image);
?>