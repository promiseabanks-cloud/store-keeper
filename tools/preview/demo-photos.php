<?php

/**
 * Preview only: draws abstract product photos with GD and attaches them to each
 * product, one per colour value, so the storefront's swatch-to-photo switching can
 * be seen without stock photography. Run with:
 *
 *   wp eval-file tools/preview/demo-photos.php --url=<subsite url>
 *
 * (No strict_types here: wp eval-file evaluates the file, so a declare would not
 * be the first statement.)
 */

use StoreKeeper\Commerce\Catalog\CatalogRepository;
use StoreKeeper\Commerce\Catalog\CatalogService;
use StoreKeeper\Commerce\Core\Audit\Actor;
use StoreKeeper\Commerce\Core\Plugin;

$container = Plugin::container();
$catalog = $container->get(CatalogRepository::class);
$service = $container->get(CatalogService::class);
$actor = Actor::staff(1);

/** Draw a soft studio-style card in one colour and return its PNG bytes. */
$draw = static function (string $hex, int $seed): string {
    [$r, $g, $b] = array_map('intval', sscanf($hex, '#%02x%02x%02x'));

    $width = 900;
    $height = 1125;
    $image = imagecreatetruecolor($width, $height);

    // A light wall behind the piece, warming towards the bottom.
    for ($y = 0; $y < $height; $y++) {
        $t = $y / $height;
        $line = imagecolorallocate(
            $image,
            (int) (248 - 14 * $t),
            (int) (243 - 16 * $t),
            (int) (234 - 18 * $t)
        );
        imageline($image, 0, $y, $width, $y, $line);
    }

    // Weave texture, faintly.
    $thread = imagecolorallocatealpha($image, 120, 108, 90, 116);
    imagesetthickness($image, 1);
    for ($x = -$height; $x < $width; $x += 34) {
        imageline($image, $x, 0, $x + $height, $height, $thread);
    }
    for ($x = 0; $x < $width + $height; $x += 34) {
        imageline($image, $x, 0, $x - $height, $height, $thread);
    }

    // A draped cloth: a rounded body with a fold, in the option's colour.
    $cloth = imagecolorallocate($image, $r, $g, $b);
    $shade = imagecolorallocate($image, (int) max(0, $r - 26), (int) max(0, $g - 26), (int) max(0, $b - 26));
    $light = imagecolorallocate($image, (int) min(255, $r + 22), (int) min(255, $g + 22), (int) min(255, $b + 22));

    $left = 150;
    $right = 750;
    $top = 250;
    $bottom = 900;
    imagefilledrectangle($image, $left, $top + 40, $right, $bottom, $cloth);
    imagefilledellipse($image, (int) (($left + $right) / 2), $top + 40, $right - $left, 180, $cloth);
    imagefilledellipse($image, (int) (($left + $right) / 2), $bottom, $right - $left, 120, $cloth);

    // Folds: vertical darker bands with a light edge, seeded per value.
    mt_srand($seed);
    for ($i = 0; $i < 5; $i++) {
        $x = $left + 60 + $i * (int) (($right - $left - 90) / 5) + mt_rand(-14, 14);
        imagefilledrectangle($image, $x, $top + 60, $x + 16, $bottom - 10, $shade);
        imageline($image, $x + 17, $top + 60, $x + 17, $bottom - 10, $light);
    }

    // Soft vignette so the card reads as a photograph, not a flat swatch.
    for ($i = 0; $i < 90; $i++) {
        $edge = imagecolorallocatealpha($image, 30, 26, 20, 122);
        imagerectangle($image, $i, $i, $width - 1 - $i, $height - 1 - $i, $edge);
    }

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
};

/** @return int attachment id */
$attach = static function (string $title, string $hex, int $seed, string $key) use ($draw): int {
    $upload = wp_upload_bits('storekeeper-demo-' . $key . '.png', null, $draw($hex, $seed));
    if (!empty($upload['error'])) {
        WP_CLI::warning('Upload failed: ' . $upload['error']);

        return 0;
    }

    $type = wp_check_filetype($upload['file']);
    $attachmentId = (int) wp_insert_attachment([
        'post_mime_type' => $type['type'] ?: 'image/png',
        'post_title' => $title,
        'post_status' => 'inherit',
    ], $upload['file']);

    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $upload['file']));

    return $attachmentId;
};

$products = $catalog->productsByStatus('active', 20);
$attached = 0;

foreach ($products as $product) {
    $productId = (int) $product['id'];
    $name = (string) $product['name'];

    if ($catalog->media($productId) !== []) {
        continue;
    }

    $general = $attach($name . ' — studio', '#e9ddc9', $productId, 'product-' . $productId . '-studio');
    if ($general > 0) {
        $service->assignMedia($productId, $general, null, $actor, 0);
        $attached++;
    }

    foreach ($catalog->productOptions($productId) as $option) {
        if ((int) $option['is_media_option'] !== 1) {
            continue;
        }

        $hexes = ['#c9a227', '#b9bcc2', '#b76e79', '#1f3d5c', '#5b3a29'];
        foreach ($catalog->optionValues((int) $option['id']) as $index => $value) {
            $hex = $value['color_hex'] !== null ? (string) $value['color_hex'] : $hexes[$index % count($hexes)];
            $attachmentId = $attach(
                $name . ' — ' . $value['value'],
                $hex,
                $productId * 100 + (int) $value['id'],
                'product-' . $productId . '-' . $value['slug']
            );
            if ($attachmentId > 0) {
                $service->assignMedia($productId, $attachmentId, (int) $value['id'], $actor, $index + 1);
                $attached++;
            }
        }
    }
}

WP_CLI::success(sprintf('%d product photos attached across %d products.', $attached, count($products)));
