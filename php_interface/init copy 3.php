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
    // МАППИНГ 1: прямое копирование 1С-код => мой код
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
    // МАППИНГ 2: строка с разделителем ";" => enum IDs (тип L, множественное)
    // =========================================================
    $parsedListMap = [
        'VNUTRENNYAYA_OTDELKA' => 'INTERIOR_DECORATION',
        'VNESHNYAYA_OTDELKA' => 'OUTSIDE_DECORATION',
        'TERMOORABOTKA' => 'HEAT_TREAMENT',
    ];

    // ID инфоблоков для enum-свойств (нужен для создания новых значений списка)
    $parsedListIblockId = 13;

    // =========================================================
    // МАППИНГ 3: агрегация из ТП => строка в товар
    // =========================================================
    $aggregateMap = [
        'FORMA' => 'SHAPE',
        'TOLSHCHINA' => 'THIKNESS',
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
    // ЧИТАЕМ СВОЙСТВА ТОВАРОВ ПАКЕТНО
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
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' | Brand cache: ' . count($brandCache) . ' items' . PHP_EOL, FILE_APPEND);

    // =========================================================
    // КЭШ ENUM-ЗНАЧЕНИЙ для parsedListMap
    // Структура: $enumCache['INTERIOR_DECORATION']['Барные стойки'] = enumId
    // =========================================================
    $enumCache = [];
    foreach ($parsedListMap as $dstCode) {
        $enumCache[$dstCode] = [];
        $enumRs = \CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC'],
            ['CODE' => $dstCode, 'IBLOCK_ID' => $parsedListIblockId]
        );
        while ($enumRow = $enumRs->Fetch()) {
            $enumCache[$dstCode][trim($enumRow['VALUE'])] = (int) $enumRow['ID'];
        }
    }
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' | Enum cache loaded for: ' . implode(', ', array_keys($enumCache)) . PHP_EOL, FILE_APPEND);

    // =========================================================
    // ЦИКЛ ПО ТОВАРАМ
    // =========================================================
    foreach ($items as $elementId => $props) {

        // -------------------------------------------------
        // БЛОК 1: DIRECT MAP
        // -------------------------------------------------
        $toWrite = [];
        $logLines = [];

        foreach ($directMap as $srcCode => $dstCode) {

            // Особая обработка BRAND (тип E — элемент инфоблока 39)
            if ($dstCode === 'BRAND') {
                $brandName = isset($props[$srcCode]) ? trim($props[$srcCode]['VALUE'] ?? '') : '';

                if ($brandName === '') {
                    $logLines[] = 'SKIP (empty)    | ' . $srcCode . ' => BRAND';
                    continue;
                }

                if (!isset($brandCache[$brandName])) {
                    // Создаём новый элемент бренда
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
                        $logLines[] = 'BRAND CREATED   | "' . $brandName . '" => new ID ' . $newId;
                    } else {
                        $logLines[] = 'BRAND FAIL      | "' . $brandName . '" | ' . $el->LAST_ERROR;
                        continue;
                    }
                }

                $toWrite['BRAND'] = $brandCache[$brandName];
                $logLines[] = 'WRITE | ' . $srcCode . ' => BRAND | "' . $brandName . '" => ID ' . $brandCache[$brandName];
                continue;
            }

            // Обычная обработка
            $srcExists = isset($props[$srcCode]);
            $value = $srcExists ? ($props[$srcCode]['VALUE'] ?? null) : null;

            // Если источник список (L) — берём текстовый лейбл
            if ($srcExists && ($props[$srcCode]['PROPERTY_TYPE'] ?? '') === 'L' && $value !== null && $value !== '') {
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
                $logLines[] = 'SKIP (no src)   | ' . $srcCode . ' => ' . $dstCode;
                continue;
            }

            if ($isEmpty) {
                $logLines[] = 'SKIP (empty)    | ' . $srcCode . ' => ' . $dstCode;
                continue;
            }

            $toWrite[$dstCode] = $value;
            $logLines[] = 'WRITE | ' . $srcCode . ' => ' . $dstCode . ' | ' . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value);
        }

        // Свойства defaultFive без источника в 1С (HEAT, UV)
        foreach ($defaultFive as $dstCode) {
            if (!isset($toWrite[$dstCode]) && !in_array($dstCode, array_values($directMap), true)) {
                $currentValue = $props[$dstCode]['VALUE'] ?? null;
                if ($currentValue === null || $currentValue === '') {
                    $toWrite[$dstCode] = 5;
                    $logLines[] = 'DEFAULT(5) | (no 1C src) => ' . $dstCode;
                }
            }
        }

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

        // -------------------------------------------------
        // БЛОК 2: PARSED LIST MAP
        // строка "Барные стойки; Пол; Стены" => enum IDs
        // -------------------------------------------------
        $parsedToWrite = [];
        $parsedLog = [];

        foreach ($parsedListMap as $srcCode => $dstCode) {
            if (!isset($props[$srcCode])) {
                $parsedLog[] = 'SKIP (no src)   | ' . $srcCode . ' => ' . $dstCode;
                continue;
            }

            $rawValue = trim($props[$srcCode]['VALUE'] ?? '');

            if ($rawValue === '') {
                $parsedLog[] = 'SKIP (empty)    | ' . $srcCode . ' => ' . $dstCode;
                continue;
            }

            // Разбиваем по ";"
            $parts = array_map('trim', explode(';', $rawValue));
            $enumIds = [];

            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }

                // Ищем в кэше
                if (!isset($enumCache[$dstCode][$part])) {
                    // Создаём новое enum-значение в свойстве
                    $newEnumId = \CIBlockPropertyEnum::Add([
                        'PROPERTY_ID' => $props[$dstCode]['ID'] ?? null,
                        'VALUE' => $part,
                        'SORT' => 100,
                        'XML_ID' => \CUtil::translit($part, 'ru', [
                            'replace_space' => '_',
                            'replace_other' => '_',
                        ]),
                    ]);

                    if ($newEnumId) {
                        $enumCache[$dstCode][$part] = (int) $newEnumId;
                        $parsedLog[] = 'ENUM CREATED    | ' . $dstCode . ' | "' . $part . '" => new ID ' . $newEnumId;
                    } else {
                        $parsedLog[] = 'ENUM FAIL       | ' . $dstCode . ' | "' . $part . '"';
                        continue;
                    }
                }

                $enumIds[] = $enumCache[$dstCode][$part];
            }

            if (!empty($enumIds)) {
                $parsedToWrite[$dstCode] = $enumIds;
                $parsedLog[] = 'WRITE | ' . $srcCode . ' => ' . $dstCode . ' | IDs: ' . implode(', ', $enumIds);
            }
        }

        if (!empty($parsedToWrite)) {
            $parsedResult = \CIBlockElement::SetPropertyValuesEx($elementId, 13, $parsedToWrite);
            $parsedLog[] = 'SetPropertyValuesEx: ' . ($parsedResult ? 'OK' : 'FAIL');
        } else {
            $parsedLog[] = 'Nothing to write';
        }

        $logBlock = '';
        foreach ($parsedLog as $line) {
            $logBlock .= date('Y-m-d H:i:s') . ' | parsedList | elem=' . $elementId . ' | ' . $line . PHP_EOL;
        }
        file_put_contents($logFile, $logBlock, FILE_APPEND);

        // -------------------------------------------------
        // БЛОК 3: AGGREGATE — из ТП => массив в товар
        // -------------------------------------------------
        $offerIds = [];
        $offerRs = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => 14,
                'PROPERTY_CML2_LINK' => $elementId,
                'ACTIVE' => 'Y',
            ],
            false,
            false,
            ['ID', 'IBLOCK_ID']
        );
        while ($offerRow = $offerRs->Fetch()) {
            $offerIds[] = (int) $offerRow['ID'];
        }

        $aggregateLog = [];

        if (empty($offerIds)) {
            $aggregateLog[] = 'no offers found';
        } else {
            // Читаем свойства ТП пакетно
            $offerItems = array_fill_keys($offerIds, []);
            \CIBlockElement::GetPropertyValuesArray(
                $offerItems,
                14,
                ['ID' => $offerIds]
            );

            $aggregateToWrite = [];

            foreach ($aggregateMap as $offerPropCode => $productPropCode) {
                $collected = [];

                foreach ($offerItems as $offerId => $offerProps) {
                    if (!isset($offerProps[$offerPropCode])) {
                        continue;
                    }

                    $val = $offerProps[$offerPropCode]['VALUE'] ?? null;

                    if ($val === null || $val === '') {
                        continue;
                    }

                    if (is_array($val)) {
                        foreach ($val as $v) {
                            if ($v !== '' && $v !== null) {
                                $collected[] = trim((string) $v);
                            }
                        }
                    } else {
                        $collected[] = trim((string) $val);
                    }
                }

                $unique = array_values(array_unique($collected));

                if (empty($unique)) {
                    $aggregateLog[] = 'SKIP (empty) | ' . $offerPropCode . ' => ' . $productPropCode;
                    continue;
                }

                $aggregateToWrite[$productPropCode] = $unique;
                $aggregateLog[] = 'WRITE | ' . $offerPropCode . ' => ' . $productPropCode . ' | ' . implode(', ', $unique);
            }

            if (!empty($aggregateToWrite)) {
                $aggResult = \CIBlockElement::SetPropertyValuesEx($elementId, 13, $aggregateToWrite);
                $aggregateLog[] = 'SetPropertyValuesEx: ' . ($aggResult ? 'OK' : 'FAIL');
            } else {
                $aggregateLog[] = 'Nothing to write';
            }
        }

        $logBlock = '';
        foreach ($aggregateLog as $line) {
            $logBlock .= date('Y-m-d H:i:s') . ' | aggregate | elem=' . $elementId . ' | ' . $line . PHP_EOL;
        }
        file_put_contents($logFile, $logBlock, FILE_APPEND);

    } // конец foreach по товарам

    file_put_contents($logFile, date('Y-m-d H:i:s') . ' | DONE' . PHP_EOL, FILE_APPEND);
});
