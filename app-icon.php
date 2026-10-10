<?php
/**
 * The online page's app icon (Home screen, notifications): the logo on white, square.
 * ?s=96|180|192|512. Drawn once per size and logo version, then served from the cache.
 */

$size  = in_array((int) ($_GET['s'] ?? 192), [96, 180, 192, 512], true) ? (int) $_GET['s'] : 192;
$logo  = __DIR__ . '/assets/img/logo.png';
$cache = sys_get_temp_dir() . '/app-icon-' . $size . '-' . (int) @filemtime($logo) . '.png';

if (!is_file($cache)) {
    $im = imagecreatetruecolor($size, $size);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    if (is_file($logo) && ($src = @imagecreatefrompng($logo))) {
        // Inside the round "maskable" safe zone: about 72% of the side.
        $w = imagesx($src);
        $h = imagesy($src);
        $k = min($size * 0.72 / $w, $size * 0.72 / $h);
        $dw = (int) round($w * $k);
        $dh = (int) round($h * $k);
        imagecopyresampled($im, $src, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
    }
    imagepng($im, $cache);
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');
readfile($cache);
