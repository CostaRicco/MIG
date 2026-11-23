<?php

use Bitrix\Main\Loader;

AddEventHandler('catalog', 'OnSuccessCatalogImport1C', function ($arParams, $file) {

    $logFile = __DIR__ . '/1c_property_sync.log';

    if (!$file || stripos($file, 'goods') === false) {
        return;
    }

    file_put_contents($logFile, date('Y-m-d H:i:s') . ' | START goods | file: ' . basename($file) . PHP_EOL, FILE_APPEND);

    if (!Loader::includeModule('iblock')) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' | ERROR: iblock not loaded' . PHP_EOL, FILE_APPEND);
        return;
    }

    // =========================================================
    // МАППИНГ: 1С-код => мой код
    // =========================================================
    $directMap = [
        'BREND' => 'BRAND',
        'VOZMOZHNOST_PEREPOLIROVKI' => 'PEREPOLIROVKA',
        'ZERKALNYY_RISUNOK' => 'MIRROR_PATTERN',
        'ISTIRAEMOST_1' => 'ISTIRAEMOST',
        'KISLOTOSTOYKOST' => 'ACID',
        'PASPORT_1' => 'PASPORT',
        'PLOTNOST_1' => 'PLOTNOST',
        'PROCHNOST_PO_SHKALE_MOOSA' => 'PROCHNOST',
        'RABOTAET_NA_PROSVET' => 'CLEARENCE',
        'TIP_KAMNYA' => 'TYPE_STONE',
        'USTOYCHIVOST_K_POVREZHDENIYAM' => 'DAMAGE',
        'USTOYCHIVOST_K_PYATNAM' => 'STAIN',
        //'' => 'VODA', //Водопоглащение
    ];

    // =========================================================
    // ПОЛУЧАЕМ ТОВАРЫ
    // =========================================================
    $items = [];
    $rs = \CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => 13, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'IBLOCK_ID', 'NAME']
    );
    while ($row = $rs->Fetch()) {
        $items[(int) $row['ID']] = $row;
    }

    if (empty($items)) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' | No products found' . PHP_EOL, FILE_APPEND);
        return;
    }

    file_put_contents($logFile, date('Y-m-d H:i:s') . ' | Products found: ' . count($items) . PHP_EOL, FILE_APPEND);

    // =========================================================
    // ЧИТАЕМ СВОЙСТВА ПАКЕТНО
    // =========================================================
    \CIBlockElement::GetPropertyValuesArray(
        $items,
        13,
        ['ID' => array_keys($items)]
    );

    // =========================================================
    // DIRECT MAP — прямое копирование свойств
    // =========================================================
    foreach ($items as $elementId => $props) {

        $toWrite = [];
        $logLines = [];

        foreach ($directMap as $srcCode => $dstCode) {

            // Источник не существует в свойствах элемента
            if (!isset($props[$srcCode])) {
                $logLines[] = "SKIP (no src)  | $srcCode => $dstCode";
                continue;
            }

            $srcProp = $props[$srcCode];
            $value = $srcProp['VALUE'] ?? null;

            // Источник пустой — не перетираем цель
            if ($value === null || $value === '' || $value === []) {
                $logLines[] = "SKIP (empty)   | $srcCode => $dstCode";
                continue;
            }

            // Если источник — список (L), берём текстовый лейбл enum
            if ($srcProp['PROPERTY_TYPE'] === 'L') {
                $value = $srcProp['VALUE_ENUM'] ?? $value;
            }

            $toWrite[$dstCode] = $value;
            $logLines[] = 'WRITE | ' . $srcCode . ' => ' . $dstCode . ' | ' . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value);
        }

        // Записываем все свойства одним вызовом
        if (!empty($toWrite)) {
            $result = \CIBlockElement::SetPropertyValuesEx($elementId, 13, $toWrite);
            $logLines[] = 'SetPropertyValuesEx: ' . ($result ? 'OK' : 'FAIL');
        } else {
            $logLines[] = 'Nothing to write';
        }

        // Пишем лог одним блоком на элемент
        $logBlock = '';
        foreach ($logLines as $line) {
            $logBlock .= date('Y-m-d H:i:s') . ' | directMap | elem=' . $elementId . ' | ' . $line . PHP_EOL;
        }
        file_put_contents($logFile, $logBlock, FILE_APPEND);
    }

    file_put_contents($logFile, date('Y-m-d H:i:s') . ' | DONE' . PHP_EOL, FILE_APPEND);
});