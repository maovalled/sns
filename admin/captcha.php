<?php
declare(strict_types=1);
session_start();

$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$codigo = '';
for ($i = 0; $i < 5; $i++) $codigo .= $chars[random_int(0, strlen($chars) - 1)];
$_SESSION['captcha'] = $codigo;

$w = 160; $h = 50;
$img = imagecreatetruecolor($w, $h);
$fondo = imagecolorallocate($img, 253, 241, 247);
$rosa  = imagecolorallocate($img, 194, 51, 127);
$claro = imagecolorallocate($img, 248, 200, 221);
imagefilledrectangle($img, 0, 0, $w, $h, $fondo);

// líneas de ruido
for ($i = 0; $i < 6; $i++) {
    imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $claro);
}
// texto (fuente integrada GD)
for ($i = 0; $i < 5; $i++) {
    imagestring($img, 5, 15 + $i * 27, random_int(8, 22), $codigo[$i], $rosa);
}
// estrellitas
for ($i = 0; $i < 12; $i++) {
    imagesetpixel($img, random_int(0, $w), random_int(0, $h), $rosa);
}

header('Content-Type: image/png');
header('Cache-Control: no-store');
imagepng($img);
imagedestroy($img);
