<?php
/**
 * One-off: register (or repair) the "Company Structure" LEFT_MENU placement that
 * opens the Org & Security console, then clear the caches so the item shows.
 * Idempotent — safe to re-run. Does NOT touch the existing "Recordings" row (392).
 *
 * The Bitrix kernel is booted FIRST, before any output, so running it via
 * `sudo -u bitrix php < register_placement.php` does not hit "headers already
 * sent".
 */

declare(strict_types=1);

const DOCROOT = '/home/bitrix/www';
const HANDLER = 'https://org.srv1802669.hstgr.cloud/app/';
const TITLE = 'Company Structure';
const APP_ID = 76; // same local app that hosts the Recordings placement

$_SERVER['DOCUMENT_ROOT'] = DOCROOT;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
require DOCROOT . '/bitrix/modules/main/include/prolog_before.php';

$settings = include DOCROOT . '/bitrix/.settings.php';
$db = $settings['connections']['value']['default'];
$m = new mysqli($db['host'], $db['login'], $db['password'], $db['database']);
if ($m->connect_error) {
    fwrite(STDERR, "db connect fail\n");
    exit(1);
}
$m->set_charset('utf8');

$h = $m->real_escape_string(HANDLER);
$res = $m->query("SELECT ID FROM b_rest_placement WHERE PLACEMENT='LEFT_MENU' AND PLACEMENT_HANDLER='$h' LIMIT 1");
$row = $res ? $res->fetch_assoc() : null;

$msg = '';
if ($row) {
    $msg = 'already registered id=' . $row['ID'];
} else {
    $t = $m->real_escape_string(TITLE);
    $ok = $m->query(
        "INSERT INTO b_rest_placement (APP_ID, USER_ID, PLACEMENT, PLACEMENT_HANDLER, TITLE, GROUP_NAME, COMMENT)
         VALUES (" . APP_ID . ", 0, 'LEFT_MENU', '$h', '$t', '', 'A7 Org & Security Manager')"
    );
    $msg = $ok ? ('inserted id=' . $m->insert_id) : ('insert failed: ' . $m->error);
}

// Clear caches so the new menu item appears without waiting.
if (function_exists('BXClearCache')) {
    BXClearCache(true, '/rest/');
    BXClearCache(true);
}
try {
    \Bitrix\Main\Application::getInstance()->getManagedCache()->cleanAll();
} catch (\Throwable $e) {
}

echo $msg . "\n";
echo "== LEFT_MENU placements ==\n";
$r = $m->query("SELECT ID, APP_ID, TITLE, PLACEMENT_HANDLER FROM b_rest_placement WHERE PLACEMENT='LEFT_MENU' ORDER BY ID");
while ($x = $r->fetch_assoc()) {
    echo json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}
echo "DONE — hard refresh the portal (Ctrl+F5); the item shows for administrators.\n";
