<?php
/**
 * generate-sizeMappings-js.php
 *
 * Читає масив $size_mappings з size-mappings.php,
 * генерує JS-об’єкт sizeMappings з полями UK, CM, KR, EU, US
 */

// Підтягнемо масив
require __DIR__ . '/size-mappings.php';

// Функція для генерації рядка JS-індентації
function indent($level) {
    return str_repeat('    ', $level);
}

echo "document.addEventListener('DOMContentLoaded', function () {\n";
echo indent(1) . "var sizeMappings = {\n";

foreach ($size_mappings as $attribute => $sizes) {
    echo indent(2) . "'{$attribute}': {\n";
    foreach ($sizes as $key => $val) {
        // Калькуляція KR із Length (cm)
        $length = floatval($val['Length (cm)']);
        $kr = (int) round($length * 10);
        $uk = $val['UK Size'];
        $cm = $val['Length (cm)'];
        $eu = $val['EU Size'];
        $us = $val['US Size'];

        echo indent(3) . "'{$key}': { ";
        echo "UK:'UK {$uk}', CM:'CM {$cm}', KR:'{$kr}', EU:'EU {$eu}', US:'US {$us}' }";
        echo ",\n";
    }
    echo indent(2) . "},\n";
}

// Додаткові зв’язки (reuse)
echo "\n" . indent(2) . "// wire up our re-uses:\n";
$reuse = [
    ['attribute_pa_jordaneu',          'attribute_pa_nikejordeu'],
    ['attribute_pa_yeezyfoameu',       'attribute_pa_yeezyslideseu'],
    ['attribute_pa_yeezyadidaseu',     'attribute_pa_yeezyslideseu'],
    ['attribute_pa_offwhiteeu',        'attribute_pa_offwhiteeu'],
    ['attribute_pa_dioreu',            'attribute_pa_dioreu'],
    ['attribute_pa_mschfredbootseu',   'attribute_pa_mschfredbootseu'],
    ['attribute_pa_mschfcrocsbootseu', 'attribute_pa_mschfcrocsbootseu'],
    ['attribute_pa_asicscpeu',         'attribute_pa_asicscpeu'],
    ['attribute_pa_newbalancestoneu',  'attribute_pa_newbalancestoneu'],
    ['attribute_pa_miharayasuhiroe',   'attribute_pa_miharayasuhiroe'],
    ['attribute_pa_yeezy-eu',          'attribute_pa_yeezy-eu'],
];

foreach ($reuse as list($alias, $target)) {
    echo indent(2) . "sizeMappings['{$alias}'] = sizeMappings['{$target}'];\n";
}

echo indent(1) . "};\n";
echo "});\n";