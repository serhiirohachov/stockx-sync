<?php
namespace StockXSync;

/**
 * Перетворює EU розмір у US з size-mappings.php, якщо можливо.
 *
 * @param string $euSize        EU розмір (наприклад '38', '42-5')
 * @param string $attributeKey  Ключ мапи, напр. 'attribute_pa_jordan_eu'
 * @return string
 */
function resolve_stockx_us_size(string $euSize, string $attributeKey = 'attribute_pa_nikejordeu'): string {
    static $cached_maps = [];

    // Якщо ще не завантажували
    if (!isset($cached_maps[$attributeKey])) {
        $path = __DIR__ . '/../../admin/size-mappings.php';
        $size_mappings = [];

        if (file_exists($path)) {
            include $path;
        }

        $cached_maps[$attributeKey] = $size_mappings[$attributeKey] ?? [];
    }

    $map = $cached_maps[$attributeKey];
    $usSize = $map[$euSize]['US Size'] ?? $euSize;

    return $usSize;
}
