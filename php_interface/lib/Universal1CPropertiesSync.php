<?php

use Bitrix\Main\Loader;

class Universal1CPropertiesSync
{
    const PRODUCT_IBLOCK_ID = 13;
    const OFFERS_IBLOCK_ID = 14;
    const OFFER_LINK_CODE = 'CML2LINK';

    /**
     * 1) Прямое копирование source => target
     * Значение пишется как есть.
     */
    protected static $directMapProducts = [
        'BREND' => 'BRAND',
        'VOZMOZHNOSTPEREPOLIROVKI' => 'PEREPOLIROVKA',
        'ZERKALNYYRISUNOK' => 'MIRROR_PATTERN',
        'ISTIRAEMOST1' => 'ISTIRAEMOST',
        'KISLOTOSTOYKOST' => 'ACID',
        'PASPORT1' => 'PASPORT',
        'PLOTNOST' => 'PLOTNOST_1',
        'PROCHNOST' => 'PROCHNOST_PO_SHKALE_MOOSA',
        'RABOTAETNAPROSVET' => 'CLEARENCE',
        'TIPKAMNYA' => 'TYPE_STONE',
        'USTOYCHIVOSTKPOVREZHDENIYAM' => 'DAMAGE',
        'USTOYCHIVOSTKPYATNAM' => 'STAIN',
    ];

    /**
     * 2) Парсинг source => target для списочных множественных свойств.
     * На входе строка/массив строк, на выходе массив ID enum-значений.
     */
    protected static $parsedListMapProducts = [
        'VNUTRENNYAYAOTDELKA' => 'INTERIOR_DECORATION',
        'VNESHNYAYAOTDELKA' => 'OUTSIDE_DECORATION',
    ];

    /**
     * 3) Агрегация из ТП в строку товара.
     * offer_property => product_property
     */
    protected static $aggregateOffersToProduct = [
        'FORMA' => 'SHAPE',
        'TOLSHCHINA' => 'THIKNESS',
    ];

    protected static $enumCache = [];
    protected static $propertyCache = [];

    protected static function getLogFile()
    {
        return $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/1c_property_sync.log';
    }

    public static function run($arParams = [])
    {
        self::log('=== START OnSuccessCatalogImport1C ===', 'RUN');
        self::log(['arParams_raw' => $arParams], 'EVENT_RAW');

        if (!Loader::includeModule('iblock')) {
            self::log('Module iblock not loaded', 'ERROR');
            return;
        }

        // Определяем файл — Bitrix может передавать по-разному
        $file = '';
        if (is_string($arParams)) {
            $file = $arParams;
        } elseif (!empty($arParams['FILE'])) {
            $file = $arParams['FILE'];
        } elseif (!empty($arParams['file'])) {
            $file = $arParams['file'];
        }

        self::log([
            'file' => $file,
            'params' => $arParams,
        ], 'EVENT');

        $isImport = $file && stripos($file, 'import') !== false;
        $isOffers = $file && stripos($file, 'offers') !== false;

        // Если file пустой — запускаем оба блока
        if (empty($file)) {
            self::log('File param empty — running both import and offers processing', 'WARNING');
            $isImport = true;
            $isOffers = true;
        }

        if ($isImport) {
            self::processProductsImport();
        }

        if ($isOffers) {
            self::processOffersImport();
        }

        self::log('=== END OnSuccessCatalogImport1C ===', 'RUN');
    }

    // -------------------------------------------------------------------------
    // Обработка товаров (import.xml)
    // -------------------------------------------------------------------------

    protected static function processProductsImport()
    {
        self::log('Start processProductsImport', 'PRODUCTS');

        $elementIds = self::getAllElementIds(self::PRODUCT_IBLOCK_ID);
        if (empty($elementIds)) {
            self::log('No products found', 'PRODUCTS');
            return;
        }

        $sourceCodes = array_unique(array_merge(
            array_keys(self::$directMapProducts),
            array_keys(self::$parsedListMapProducts)
        ));

        $items = self::loadElementProperties(self::PRODUCT_IBLOCK_ID, $elementIds, $sourceCodes);

        foreach ($items as $elementId => $item) {
            $update = [];

            $directValues = self::buildDirectPropertyValues($item);
            if (!empty($directValues)) {
                $update = array_merge($update, $directValues);
            }

            $parsedListValues = self::buildParsedListPropertyValues($item);
            if (!empty($parsedListValues)) {
                $update = array_merge($update, $parsedListValues);
            }

            if (!empty($update)) {
                \CIBlockElement::SetPropertyValuesEx($elementId, self::PRODUCT_IBLOCK_ID, $update);

                self::log([
                    'element_id' => $elementId,
                    'element_name' => $item['NAME'],
                    'update' => $update,
                ], 'PRODUCT_UPDATE');
            }
        }

        self::log('End processProductsImport', 'PRODUCTS');
    }

    // -------------------------------------------------------------------------
    // Обработка торговых предложений (offers.xml)
    // -------------------------------------------------------------------------

    protected static function processOffersImport()
    {
        self::log('Start processOffersImport', 'OFFERS');

        $offerIds = self::getAllElementIds(self::OFFERS_IBLOCK_ID);
        if (empty($offerIds)) {
            self::log('No offers found', 'OFFERS');
            return;
        }

        $offerCodes = array_unique(array_merge(
            [self::OFFER_LINK_CODE],
            array_keys(self::$aggregateOffersToProduct)
        ));

        $offers = self::loadElementProperties(self::OFFERS_IBLOCK_ID, $offerIds, $offerCodes);

        $groupedByProduct = [];

        foreach ($offers as $offerId => $offer) {
            $productId = (int) self::extractSingleValue($offer, self::OFFER_LINK_CODE);
            if ($productId <= 0) {
                continue;
            }

            foreach (self::$aggregateOffersToProduct as $offerCode => $productCode) {
                $values = self::extractNormalizedValues($offer, $offerCode);
                foreach ($values as $value) {
                    $groupedByProduct[$productId][$productCode][$value] = $value;
                }
            }
        }

        foreach ($groupedByProduct as $productId => $productProps) {
            $update = [];

            foreach ($productProps as $productCode => $valuesMap) {
                $values = array_values($valuesMap);
                if (!empty($values)) {
                    $update[$productCode] = implode(', ', $values);
                }
            }

            if (!empty($update)) {
                \CIBlockElement::SetPropertyValuesEx($productId, self::PRODUCT_IBLOCK_ID, $update);

                self::log([
                    'product_id' => $productId,
                    'update' => $update,
                ], 'AGGREGATE_UPDATE');
            }
        }

        self::log('End processOffersImport', 'OFFERS');
    }

    // -------------------------------------------------------------------------
    // Построение массива обновлений
    // -------------------------------------------------------------------------

    protected static function buildDirectPropertyValues(array $item)
    {
        $result = [];

        foreach (self::$directMapProducts as $sourceCode => $targetCode) {
            $values = self::extractNormalizedValues($item, $sourceCode);
            if (empty($values)) {
                continue;
            }

            $result[$targetCode] = count($values) === 1 ? $values[0] : implode(', ', $values);

            self::log([
                'element_id' => $item['ID'],
                'source' => $sourceCode,
                'target' => $targetCode,
                'values' => $values,
            ], 'DIRECT_MAP');
        }

        return $result;
    }

    protected static function buildParsedListPropertyValues(array $item)
    {
        $result = [];

        foreach (self::$parsedListMapProducts as $sourceCode => $targetCode) {
            $values = self::extractNormalizedValues($item, $sourceCode);
            if (empty($values)) {
                continue;
            }

            $enumIds = self::resolveEnumIds(self::PRODUCT_IBLOCK_ID, $targetCode, $values);
            if (empty($enumIds)) {
                continue;
            }

            $result[$targetCode] = $enumIds;

            self::log([
                'element_id' => $item['ID'],
                'source' => $sourceCode,
                'target' => $targetCode,
                'values' => $values,
                'enum_ids' => $enumIds,
            ], 'PARSED_LIST_MAP');
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Получение элементов и свойств
    // -------------------------------------------------------------------------

    protected static function getAllElementIds($iblockId)
    {
        $ids = [];

        $rs = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId],
            false,
            false,
            ['ID']
        );

        while ($row = $rs->Fetch()) {
            $ids[] = (int) $row['ID'];
        }

        return $ids;
    }

    protected static function loadElementProperties($iblockId, array $elementIds, array $propertyCodes)
    {
        $items = [];

        $rs = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'ID' => $elementIds],
            false,
            false,
            ['ID', 'IBLOCK_ID', 'NAME']
        );

        while ($row = $rs->Fetch()) {
            $row['PROPERTIES'] = [];
            $items[(int) $row['ID']] = $row;
        }

        if (empty($items) || empty($propertyCodes)) {
            return $items;
        }

        \CIBlockElement::GetPropertyValuesArray(
            $items,
            $iblockId,
            ['ID' => array_keys($items)],
            ['CODE' => $propertyCodes]
        );

        return $items;
    }

    // -------------------------------------------------------------------------
    // Вспомогательные методы
    // -------------------------------------------------------------------------

    protected static function extractSingleValue(array $item, $propertyCode)
    {
        $values = self::extractNormalizedValues($item, $propertyCode);
        return empty($values) ? null : reset($values);
    }

    protected static function extractNormalizedValues(array $item, $propertyCode)
    {
        $result = [];

        if (!isset($item['PROPERTIES'][$propertyCode])) {
            return $result;
        }

        $property = $item['PROPERTIES'][$propertyCode];
        $rawValue = isset($property['VALUE']) ? $property['VALUE'] : null;

        if (is_array($rawValue)) {
            foreach ($rawValue as $value) {
                foreach (self::splitCompositeValue($value) as $part) {
                    $result[$part] = $part;
                }
            }
        } else {
            foreach (self::splitCompositeValue($rawValue) as $part) {
                $result[$part] = $part;
            }
        }

        return array_values($result);
    }

    protected static function splitCompositeValue($value)
    {
        $result = [];

        $value = trim((string) $value);
        if ($value === '') {
            return $result;
        }

        $parts = preg_split('/[;,]+/u', $value);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $result[] = $part;
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Работа с enum-значениями списочных свойств
    // -------------------------------------------------------------------------

    protected static function resolveEnumIds($iblockId, $propertyCode, array $values)
    {
        $enumMap = self::getEnumMap($iblockId, $propertyCode);
        if (empty($enumMap)) {
            return [];
        }

        $result = [];

        foreach ($values as $value) {
            $normalized = self::normalizeCompareValue($value);

            if (isset($enumMap[$normalized])) {
                $result[] = $enumMap[$normalized];
            } else {
                self::log([
                    'iblock_id' => $iblockId,
                    'property_code' => $propertyCode,
                    'value_not_found' => $value,
                ], 'ENUM_NOT_FOUND');
            }
        }

        return array_values(array_unique($result));
    }

    protected static function getEnumMap($iblockId, $propertyCode)
    {
        $cacheKey = $iblockId . ':' . $propertyCode;

        if (isset(self::$enumCache[$cacheKey])) {
            return self::$enumCache[$cacheKey];
        }

        $property = self::getPropertyMeta($iblockId, $propertyCode);
        if (!$property) {
            self::$enumCache[$cacheKey] = [];
            return self::$enumCache[$cacheKey];
        }

        $map = [];

        $rsEnum = \CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC', 'VALUE' => 'ASC'],
            ['PROPERTY_ID' => $property['ID']]
        );

        while ($enum = $rsEnum->Fetch()) {
            $value = trim((string) $enum['VALUE']);
            if ($value === '') {
                continue;
            }
            $map[self::normalizeCompareValue($value)] = (int) $enum['ID'];
        }

        self::$enumCache[$cacheKey] = $map;

        return self::$enumCache[$cacheKey];
    }

    protected static function getPropertyMeta($iblockId, $propertyCode)
    {
        $cacheKey = $iblockId . ':' . $propertyCode;

        if (isset(self::$propertyCache[$cacheKey])) {
            return self::$propertyCache[$cacheKey];
        }

        $property = \CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode]
        )->Fetch();

        self::$propertyCache[$cacheKey] = $property ?: null;

        return self::$propertyCache[$cacheKey];
    }

    protected static function normalizeCompareValue($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return mb_strtolower($value);
    }

    // -------------------------------------------------------------------------
    // Логирование
    // -------------------------------------------------------------------------

    protected static function log($message, $title = 'UNIVERSAL_1C_SYNC')
    {
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        AddMessage2Log(
            date('Y-m-d H:i:s') . ' | ' . $message,
            $title,
            0,
            self::getLogFile()
        );
    }
}