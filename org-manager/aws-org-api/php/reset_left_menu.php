<?php
/**
 * Reset the Bitrix24 left-menu personalisation so it rebuilds from the current
 * placement list. The desktop left menu persists its item list + order per user
 * in b_user_option (left_menu_*), and reconciles added/removed app placements
 * only lazily — which is why a raw-SQL placement change (Recordings removed,
 * Company Structure added) did not show up.
 *
 * Pass a user id as argv[1] to reset one user (test), or "all" to reset everyone.
 * Default: user 2311 (the admin used for testing).
 */

declare(strict_types=1);

const DOCROOT = '/home/bitrix/www';
$_SERVER['DOCUMENT_ROOT'] = DOCROOT;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
require DOCROOT . '/bitrix/modules/main/include/prolog_before.php';

$target = $argv[1] ?? '2311';

$settings = include DOCROOT . '/bitrix/.settings.php';
$db = $settings['connections']['value']['default'];
$m = new mysqli($db['host'], $db['login'], $db['password'], $db['database']);
$m->set_charset('utf8');

if ($target === 'all') {
    $where = "CATEGORY='intranet' AND NAME LIKE 'left_menu_%'";
} else {
    $uid = (int) $target;
    $where = "USER_ID=$uid AND CATEGORY='intranet' AND NAME LIKE 'left_menu_%'";
}

$before = $m->query("SELECT COUNT(*) c FROM b_user_option WHERE $where")->fetch_assoc()['c'];
$m->query("DELETE FROM b_user_option WHERE $where");
$deleted = $m->affected_rows;

// Drop the per-user option cache so the deletion is seen immediately.
if (function_exists('BXClearCache')) {
    BXClearCache(true, '/user_option/');
    BXClearCache(true);
}
try {
    \Bitrix\Main\Application::getInstance()->getManagedCache()->cleanAll();
} catch (\Throwable $e) {
}

echo json_encode(['ok' => true, 'target' => $target, 'matched' => (int) $before, 'deleted' => $deleted], JSON_UNESCAPED_SLASHES) . "\n";
echo "DONE — reload the portal; the left menu rebuilds from current placements.\n";
