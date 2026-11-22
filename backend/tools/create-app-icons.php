
<?php

$source = __DIR__ . '/../../assets/images/logo/icon.png';

if (!extension_loaded('gd')) {
    exit("GD extension is not enabled.\n");
}

if (!is_file($source)) {
    exit("Source icon not found.\n");
}

$image = imagecreatefrompng($source);

if (!$image) {
    exit("Unable to open source icon.\n");
}

$directory = dirname($source);

$sizes = [
    192 => 'icon-192.png',
    512 => 'icon-512.png',
    180 => 'apple-touch-icon.png',
];

foreach ($sizes as $size => $filename) {
    $output = imagecreatetruecolor($size, $size);

    imagealphablending($output, false);
    imagesavealpha($output, true);

    imagecopyresampled(
        $output,
        $image,
        0, 0, 0, 0,
        $size, $size,
        imagesx($image),
        imagesy($image)
    );

    imagepng($output, $directory . '/' . $filename);
    imagedestroy($output);

    echo "$filename created ($size x $size)\n";
}

imagedestroy($image);

echo "App icons created successfully.\n";
