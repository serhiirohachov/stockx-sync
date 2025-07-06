<?php
namespace StockXSync;

class SizeMapper {

    protected static array $mappings = [];

    /**
     * Завантажує мапу для атрибута (бренду) лише один раз
     */
    protected static function load(string $attributeKey): void {
        if (isset(self::$mappings[$attributeKey])) {
            return;
        }

        $path = __DIR__ . '/../../admin/size-mappings.php';
        $size_mappings = [];

        if (file_exists($path)) {
            include $path;
        }

        self::$mappings[$attributeKey] = $size_mappings[$attributeKey] ?? [];
    }

    /**
     * Повертає US розмір для вказаного EU розміру
     */
    public static function get_us_size(string $euSize, string $attributeKey): string {
        self::load($attributeKey);
        return self::$mappings[$attributeKey][$euSize]['US Size'] ?? $euSize;
    }

    /**
     * Автоматично визначає ключ атрибута для мапінгу за product ID
     */
    public static function guess_attribute_key_by_product_id(int $product_id): string {
        $brand = get_post_meta($product_id, 'pa_brand', true) ?: '';
        $brand = strtolower(trim($brand));

        switch ($brand) {
            case 'jordan':
                return 'attribute_pa_jordan_eu';
            case 'yeezy':
                return 'attribute_pa_yeezy_eu';
            case 'off-white':
                return 'attribute_pa_offwhite_eu';
            case 'dior':
                return 'attribute_pa_dior_eu';
            case 'asics':
                return 'attribute_pa_asics_eu';
            case 'mschf':
                return 'attribute_pa_mschf_eu';
            default:
                return 'attribute_pa_nikejordeu'; // fallback
        }
    }
}
