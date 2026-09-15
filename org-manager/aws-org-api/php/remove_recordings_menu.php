<?php
/**
 * Remove the "Recordings" LEFT_MENU item (b_rest_placement id 392) so the slot is
 * effectively replaced by "Company Structure" (id 394). Reversible: the full row
 * is printed before deletion, and re-inserting it restores the item. Only the
 * MENU ENTRY is removed — the Recordings app on Hostinger is untouched.
 *
 * Guarded: only deletes when the row is exactly the known Recordings LEFT_MENU
 * handler, so a re-run (or a shifted id) cannot remove the wrong item.
 */

declare(strict_types=1);

const DOCROOT = '/home/bitrix/www';
const REC_HANDLER = 'https://recordings.srv1802669.hstgr.cloud/';

$_SERVER['DOCUMENT_ROOT'] = DOCROOT;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);
require DOCROOT . '/bitrix/modules/main/include/prolog_before.php';

$settings = include DOCROOT . '/bitrix/.settings.php';
$db = $settings['connections']['value']['default'];
$m = new mysqli($db['host'], $db['login'], $db['password'], $db['database']);
$m->set_charset('utf8');

$h = $m->real_escape_string(REC_HANDLER);
$res = $m->query("SELECT * FROM b_rest_placement WHERE PLACEMENT='LEFT_MENU' AND PLACEMENT_HANDLER='$h'");
$rows = [];
while ($res && ($r = $res->fetch_assoc())) {
    $rows[] = $r;
}

if (!$rows) {
    echo "no Recordings LEFT_MENU row found (already removed)\n";
} else {
    echo "=== BACKUP (re-insert these values to restore) ===\n";
    foreach ($rows as $r) {
        echo json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
    $m->query("DELETE FROM b_rest_placement WHERE PLACEMENT='LEFT_MENU' AND PLACEMENT_HANDLER='$h'");
    echo 'deleted rows=' . $m->affected_rows . "\n";
}

if (function_exists('BXClearCache')) {
    BXClearCache(true, '/rest/');
    BXClearCache(true);
}
try {
    \Bitrix\Main\Application::getInstance()->getManagedCache()->cleanAll();
} catch (\Throwable $e) {
}

echo "== LEFT_MENU placements now ==\n";
$r = $m->query("SELECT ID, APP_ID, TITLE, PLACEMENT_HANDLER FROM b_rest_placement WHERE PLACEMENT='LEFT_MENU' ORDER BY ID");
while ($x = $r->fetch_assoc()) {
    echo json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}
echo "DONE — hard refresh the portal (Ctrl+F5).\n";
