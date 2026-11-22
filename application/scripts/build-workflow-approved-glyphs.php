<?php

// Convert the approved atlas's existing foreground alpha into plain SVG paths.
// Nothing is redrawn: every source pixel keeps its measured opacity and position.
// The resulting sprite needs no browser image filters or nested resource masks.
$root = $argv[1] ?? dirname(__DIR__);
$source = $root.'/resources/images/workflow-nodes/duotone-approved.png';
if (! extension_loaded('gd')) {
    throw new RuntimeException('The offline SVG generator requires PHP GD.');
}
$image = imagecreatefrompng($source);
if ($image === false) {
    throw new RuntimeException('Cannot read the approved node artwork.');
}
$windows = [
    'contact-find' => [190, 202], 'contact-create' => [515, 202],
    'contact-update' => [822, 202], 'lead-create' => [1145, 202],
    'lead-update' => [190, 465], 'condition' => [515, 465],
    'loop' => [822, 465], 'assign' => [1145, 465],
    'http' => [190, 729], 'telegram' => [822, 729], 'manual' => [1145, 729],
];
$sourceHash = hash_file('sha256', $source);
$svg = '<svg xmlns="http://www.w3.org/2000/svg" data-source-sha256="'.$sourceHash.'">';
$svg .= '<desc>Original approved node foregrounds, encoded as plain currentColor paths.</desc>';
$statistics = [];
foreach ($windows as $name => [$left, $top]) {
    $paths = [];
    $pixels = 0;
    for ($y = 0; $y < 152; $y++) {
        $runStart = 0;
        $runAlpha = 0;
        for ($x = 0; $x <= 152; $x++) {
            $alpha = 0;
            if ($x < 152) {
                $rgb = imagecolorat($image, $left + $x, $top + $y);
                $minimum = min(($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255);
                // The same .42 threshold and .58 range as the previous SVG filter.
                $alpha = (int) round(max(0, min(255, ($minimum - .42 * 255) / .58)));
            }
            if ($alpha === $runAlpha && $x < 152) continue;
            if ($runAlpha > 0) {
                $width = $x - $runStart;
                $paths[$runAlpha] = ($paths[$runAlpha] ?? '').'M'.$runStart.' '.$y.'h'.$width.'v1h-'.$width.'z';
                $pixels += $width;
            }
            $runStart = $x;
            $runAlpha = $alpha;
        }
    }
    if ($pixels < 100 || $pixels > 152 * 152 * .85) {
        throw new RuntimeException('Invalid foreground coverage for '.$name);
    }
    ksort($paths);
    $svg .= '<symbol id="'.$name.'" viewBox="0 0 152 152" data-foreground-pixels="'.$pixels.'">';
    foreach ($paths as $alpha => $path) {
        $opacity = rtrim(rtrim(sprintf('%.6F', $alpha / 255), '0'), '.');
        $svg .= '<path fill="currentColor" fill-opacity="'.$opacity.'" d="'.$path.'"/>';
    }
    $svg .= '</symbol>';
    $statistics[$name] = ['pixels' => $pixels, 'paths' => count($paths)];
}
unset($image);
$svg .= '</svg>';
$filename = 'approved-glyphs-'.substr(hash('sha256', $svg), 0, 12).'.svg';
$destination = $argv[2] ?? $root.'/public/images/workflows';
if (! is_dir($destination)) mkdir($destination, 0775, true);
file_put_contents($destination.'/'.$filename, $svg);
echo json_encode(['asset' => $filename, 'bytes' => strlen($svg), 'source_sha256' => $sourceHash, 'glyphs' => $statistics], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
