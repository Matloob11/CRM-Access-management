<?php
/**
 * Re-sync the left-menu marketplace items from the current REST placements by
 * running Bitrix's own EventHandler::reloadPlacementLeftMenu() via reflection.
 *
 * The desktop left menu reads app items from the site option
 * intranet / left_menu_items_marketplace_<SITE_ID>, which is refreshed only by the
 * placement register/unregister events. This routine reads the live placement list
 * and updates that one option to match. No schema change, no restart.
 */

declare(strict_types=1);

$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
require '/home/bitrix/www/bitrix/modules/main/include/prolog_before.php';

\Bitrix\Main\Loader::includeModule('intranet');
\Bitrix\Main\Loader::includeModule('rest');

if (!defined('SITE_ID')) {
    define('SITE_ID', 's1');
}

$class = '\\Bitrix\\Intranet\\Integration\\Rest\\EventHandler';

echo 'before: ' . \Bitrix\Main\Config\Option::get('intranet', 'left_menu_items_marketplace_' . SITE_ID) . "\n\n";

try {
    $ref = new \ReflectionMethod($class, 'reloadPlacementLeftMenu');
    $ref->setAccessible(true);
    $ref->invoke(null);
    echo "reloadPlacementLeftMenu() invoked\n\n";
} catch (\Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}

try {
    \Bitrix\Intranet\Composite\CacheProvider::deleteUserCache();
} catch (\Throwable $e) {
}
if (function_exists('BXClearCache')) {
    BXClearCache(true, '/');
}

echo 'after:  ' . \Bitrix\Main\Config\Option::get('intranet', 'left_menu_items_marketplace_' . SITE_ID) . "\n";
echo "DONE\n";
