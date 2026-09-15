<?php
/**
 * Invalidate the REST placement / app caches after the raw-SQL menu edits, so the
 * left menu rebuilds from the current b_rest_placement rows (Recordings gone,
 * Company Structure present). Raw INSERT/DELETE does not call the ORM's cache
 * invalidation, which is why the menu kept showing the old item.
 */

declare(strict_types=1);

const DOCROOT = '/home/bitrix/www';
$_SERVER['DOCUMENT_ROOT'] = DOCROOT;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
require DOCROOT . '/bitrix/modules/main/include/prolog_before.php';

\Bitrix\Main\Loader::includeModule('rest');

$done = [];

foreach ([
    '\\Bitrix\\Rest\\PlacementTable',
    '\\Bitrix\\Rest\\AppTable',
    '\\Bitrix\\Rest\\PlacementLangTable',
] as $cls) {
    if (class_exists($cls)) {
        try {
            $cls::getEntity()->cleanCache();
            $done[] = "cleanCache $cls";
        } catch (\Throwable $e) {
            $done[] = "err $cls: " . $e->getMessage();
        }
    }
}

// Managed + file caches.
try {
    \Bitrix\Main\Application::getInstance()->getManagedCache()->cleanAll();
    $done[] = 'managed cleanAll';
} catch (\Throwable $e) {
    $done[] = 'managed err: ' . $e->getMessage();
}
if (function_exists('BXClearCache')) {
    BXClearCache(true);
    $done[] = 'BXClearCache(true)';
}

// Tagged cache used by the interface menu, if present.
try {
    $tagged = \Bitrix\Main\Application::getInstance()->getTaggedCache();
    foreach (['intranet_menu', 'menu', 'rest_placement'] as $tag) {
        $tagged->clearByTag($tag);
    }
    $done[] = 'tagged cleared';
} catch (\Throwable $e) {
    $done[] = 'tagged err: ' . $e->getMessage();
}

// Reset OPcache in this process (web workers reset on their own cycle).
if (function_exists('opcache_reset')) {
    @opcache_reset();
    $done[] = 'opcache_reset';
}

echo json_encode(['ok' => true, 'done' => $done], JSON_UNESCAPED_SLASHES) . "\n";
echo "DONE — hard refresh (Ctrl+F5) the portal.\n";
