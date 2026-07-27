<?php

/**
 * Renders the LETS App Store icon (1200×1200 PNG) from the brand mark.
 *
 * Shopify's listing needs a raster icon, not the SVG the app itself uses, and it
 * is rendered small in a grid — so this draws the MARK ALONE (the two facing
 * half-discs) with no wordmark: "let's" at 48px in a search result is a smudge,
 * while the mark stays legible.
 *
 * Kept as a script rather than a one-off so the icon can be re-cut when the brand
 * changes, instead of becoming a mystery binary nobody can reproduce.
 *
 * Run: php scripts/make-app-icon.php
 */

// === CONSTANTS ===
const CANVAS = 1200;              // Shopify's required icon size.
const RADIUS = 300;               // Half-disc radius; ~50% of the canvas width.
const GAP = 28;                   // The breathing space between the two discs.
const BLUE = [0x00, 0x66, 0xFF];  // Brand blue — the upper disc.
const ORCHID = [0xEE, 0x82, 0xEE]; // Brand orchid — the lower disc.
const BG = [0xFF, 0xFF, 0xFF];    // Shopify rounds the corners itself; fill the frame.
const OUT = __DIR__.'/../public/images/lets-app-icon.png';

$image = imagecreatetruecolor(CANVAS, CANVAS);
imagealphablending($image, true);
imageantialias($image, true);

$bg = imagecolorallocate($image, ...BG);
$blue = imagecolorallocate($image, ...BLUE);
$orchid = imagecolorallocate($image, ...ORCHID);

imagefilledrectangle($image, 0, 0, CANVAS, CANVAS, $bg);

// The mark is two half-discs facing each other, centred as one optical unit:
// total height = both radii + the gap between the flat edges.
$markHeight = (RADIUS * 2) + GAP;
$top = intdiv(CANVAS - $markHeight, 2);
$cx = intdiv(CANVAS, 2);

// GD angles start at 3 o'clock and increase CLOCKWISE (y grows downward), so
// 0°–180° is the LOWER half of a circle and 180°–360° the upper half.
$diameter = RADIUS * 2;

// Upper shape: flat edge on top, bulging down.
imagefilledarc($image, $cx, $top, $diameter, $diameter, 0, 180, $blue, IMG_ARC_PIE);

// Lower shape: flat edge on the bottom, bulging up.
$bottomFlat = $top + $markHeight;
imagefilledarc($image, $cx, $bottomFlat, $diameter, $diameter, 180, 360, $orchid, IMG_ARC_PIE);

imagepng($image, OUT, 9);
imagedestroy($image);

printf("Wrote %s (%d bytes)%s", OUT, filesize(OUT), PHP_EOL);
