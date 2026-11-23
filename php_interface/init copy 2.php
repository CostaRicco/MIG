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
        // '' => 'VODA', // Водопоглащение
    ];

    // Свойства с дефолтным значением 5, если из 1С пришло пусто или нет совсем
    $defaultFive = ['DAMAGE', 'HEAT', 'ACID', 'STAIN', 'UV'];

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
    // КЭШ БРЕНДОВ: название => ID элемента инфоблока 39
    // =========================================================
    $brandCache = [];
    $brandRs = \CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => 39, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'NAME']
    );
    while ($brandRow = $brandRs->Fetch()) {
        $brandCache[trim($brandRow['NAME'])] = (int) $brandRow['ID'];
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' | Brand cache loaded: ' . count($brandCache) . ' items' . PHP_EOL, FILE_APPEND);

    // =========================================================
    // DIRECT MAP — прямое копирование свойств
    // =========================================================
    foreach ($items as $elementId => $props) {

        $toWrite = [];
        $logLines = [];

        foreach ($directMap as $srcCode => $dstCode) {

            // --- Особая обработка BRAND (тип E — элемент инфоблока) ---
            if ($dstCode === 'BRAND') {
                $brandName = isset($props[$srcCode]) ? trim($props[$srcCode]['VALUE'] ?? '') : '';

                if ($brandName === '') {
                    $logLines[] = 'SKIP (empty)   | ' . $srcCode . ' => BRAND';
                    continue;
                }

                // Ищем в кэше
                if (!isset($brandCache[$brandName])) {
                    // Создаём новый элемент бренда в инфоблоке 39
                    $el = new \CIBlockElement();
                    $newId = $el->Add([
                        'IBLOCK_ID' => 39,
                        'NAME' => $brandName,
                        'ACTIVE' => 'Y',
                        'CODE' => \CUtil::translit($brandName, 'ru', [
                            'replace_space' => '-',
                            'replace_other' => '-',
                        ]),
                    ]);

                    if ($newId) {
                        $brandCache[$brandName] = (int) $newId;
                        $logLines[] = 'BRAND CREATED | "' . $brandName . '" => new ID ' . $newId;
                    } else {
                        $logLines[] = 'BRAND CREATE FAIL | "' . $brandName . '" | ' . $el->LAST_ERROR;
                        continue;
                    }
                }

                $toWrite['BRAND'] = $brandCache[$brandName];
                $logLines[] = 'WRITE | ' . $srcCode . ' => BRAND | "' . $brandName . '" => ID ' . $brandCache[$brandName];
                continue;
            }

            // --- Обычная обработка ---
            $srcExists = isset($props[$srcCode]);
            $value = $srcExists ? ($props[$srcCode]['VALUE'] ?? null) : null;

            // Если источник — список (L), берём текстовый лейбл
            if ($srcExists && $props[$srcCode]['PROPERTY_TYPE'] === 'L' && ($value !== null && $value !== '')) {
                $value = $props[$srcCode]['VALUE_ENUM'] ?? $value;
            }

            $isEmpty = ($value === null || $value === '' || $value === []);

            // Дефолт 5 для нужных свойств
            if ($isEmpty && in_array($dstCode, $defaultFive, true)) {
                $toWrite[$dstCode] = 5;
                $logLines[] = 'DEFAULT(5) | ' . $srcCode . ' => ' . $dstCode;
                continue;
            }

            if (!$srcExists) {
                $logLines[] = 'SKIP (no src)  | ' . $srcCode . ' => ' . $dstCode;
                continue;
            }

            if ($isEmpty) {
                $logLines[] = 'SKIP (empty)   | ' . $srcCode . ' => ' . $dstCode;
                continue;
            }

            $toWrite[$dstCode] = $value;
            $logLines[] = 'WRITE | ' . $srcCode . ' => ' . $dstCode . ' | ' . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value);
        }

        // Свойства defaultFive, которых нет вообще в directMap (HEAT, UV — не маппятся из 1С)
        foreach ($defaultFive as $dstCode) {
            if (!isset($toWrite[$dstCode]) && !in_array($dstCode, array_values($directMap), true)) {
                // Проверяем текущее значение в props по коду цели
                $currentValue = $props[$dstCode]['VALUE'] ?? null;
                if ($currentValue === null || $currentValue === '') {
                    $toWrite[$dstCode] = 5;
                    $logLines[] = 'DEFAULT(5) | (no 1C src) => ' . $dstCode;
                }
            }
        }

        // Записываем все свойства одним вызовом
        if (!empty($toWrite)) {
            $result = \CIBlockElement::SetPropertyValuesEx($elementId, 13, $toWrite);
            $logLines[] = 'SetPropertyValuesEx: ' . ($result ? 'OK' : 'FAIL');
        } else {
            $logLines[] = 'Nothing to write';
        }

        $logBlock = '';
        foreach ($logLines as $line) {
            $logBlock .= date('Y-m-d H:i:s') . ' | directMap | elem=' . $elementId . ' | ' . $line . PHP_EOL;
        }
        file_put_contents($logFile, $logBlock, FILE_APPEND);
    }

    file_put_contents($logFile, date('Y-m-d H:i:s') . ' | DONE' . PHP_EOL, FILE_APPEND);
});