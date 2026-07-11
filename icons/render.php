<?php
// Renders the app icon as a PNG at the requested size using GD
// (iOS home-screen icons require PNG; the SVG covers everything else).

function render_icon(int $s): void {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    if (!function_exists('imagecreatetruecolor')) { fallback_png(); return; }

    $img = imagecreatetruecolor($s, $s);
    // vertical green gradient background
    for ($y = 0; $y < $s; $y++) {
        $t = $y / $s;
        $col = imagecolorallocate($img,
            (int)(15 + (7 - 15) * $t),
            (int)(92 + (53 - 92) * $t),
            (int)(70 + (42 - 70) * $t));
        imageline($img, 0, $y, $s, $y, $col);
    }
    $white = imagecolorallocate($img, 255, 255, 255);
    $dark  = imagecolorallocate($img, 4, 120, 87);

    // heart: two circles + triangle
    $cx = $s / 2; $u = $s / 512;
    $r  = (int)(78 * $u);
    $cy = (int)(210 * $u);
    imagefilledellipse($img, (int)($cx - 70 * $u), $cy, $r * 2, $r * 2, $white);
    imagefilledellipse($img, (int)($cx + 70 * $u), $cy, $r * 2, $r * 2, $white);
    imagefilledpolygon($img, [
        (int)($cx - 138 * $u), (int)(240 * $u),
        (int)($cx + 138 * $u), (int)(240 * $u),
        (int)$cx, (int)(410 * $u),
    ], $dark === false ? $white : $white);

    // pulse line
    $pts = [[120,268],[176,268],[196,224],[228,308],[258,244],[276,268],[372,268]];
    $t = max(2, (int)(20 * $u));
    for ($i = 0; $i < count($pts) - 1; $i++) {
        thick_line($img, (int)($pts[$i][0]*$u), (int)($pts[$i][1]*$u),
                   (int)($pts[$i+1][0]*$u), (int)($pts[$i+1][1]*$u), $t, $dark);
    }
    imagepng($img);
    imagedestroy($img);
}

function thick_line($img, int $x1, int $y1, int $x2, int $y2, int $t, $col): void {
    $len = max(1, (int)hypot($x2 - $x1, $y2 - $y1));
    for ($i = 0; $i <= $len; $i++) {
        $x = $x1 + ($x2 - $x1) * $i / $len;
        $y = $y1 + ($y2 - $y1) * $i / $len;
        imagefilledellipse($img, (int)$x, (int)$y, $t, $t, $col);
    }
}

function fallback_png(): void {
    // 1x1 green pixel if GD is unavailable
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkkPhfDwADgwHXYK0LIgAAAABJRU5ErkJggg==');
}
