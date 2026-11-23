<?php
use Bitrix\Main\Loader;

AddEventHandler('catalog', 'OnSuccessCatalogImport1C', function ($arParams, $file) {
    if (!$file || stripos(basename($file), 'offers') === false || !Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
        return;
    }
    $rs = \CIBlockElement::GetList([], ['IBLOCK_ID' => 14, '<=CATALOG_QUANTITY' => 0], false, false, ['ID']);
    while ($row = $rs->Fetch()) {
        $ok = \CIBlockElement::Delete($row['ID']);
        file_put_contents(__DIR__ . '/delete_zero_offers.log', date('Y-m-d H:i:s') . ' | offer ' . $row['ID'] . ': ' . ($ok ? 'OK' : 'FAIL') . PHP_EOL, FILE_APPEND);
    }
});