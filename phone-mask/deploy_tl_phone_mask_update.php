#!/usr/bin/env php
<?php
/**
 * Deploy / patch A7 phone mask on this Bitrix host.
 * Copies allow-list + JS + tool. Never writes an unlock password into git or
 * into the host config — keep A7_TL_PHONE_MASK_PASSWORD on the server.
 *
 * Run: sudo -u bitrix php /tmp/deploy_tl_phone_mask_update.php
 */
declare(strict_types=1);

$doc = '/home/bitrix/www';
$cfgPath = $doc . '/bitrix/php_interface/a7_tl_phone_mask.config.php';
$toolPath = $doc . '/bitrix/tools/a7_tl_phone_mask.php';
$jsPath = $doc . '/bitrix/js/a7_tl_phone_mask.js';
$initPath = $doc . '/bitrix/php_interface/init.php';
$srcDir = __DIR__;

$srcCfg = $srcDir . '/a7_tl_phone_mask.config.php';
$srcJs = $srcDir . '/a7_tl_phone_mask.js';
$srcTool = $srcDir . '/a7_tl_phone_mask.php';

if (is_readable($srcJs)) {
    @copy($jsPath, $jsPath . '.bak.' . date('YmdHis'));
    copy($srcJs, $jsPath);
    echo "Wrote JS\n";
}
if (is_readable($srcTool)) {
    @copy($toolPath, $toolPath . '.bak.' . date('YmdHis'));
    copy($srcTool, $toolPath);
    echo "Wrote tool\n";
}

if (is_readable($srcCfg)) {
    $incoming = include $srcCfg;
    $existing = is_readable($cfgPath) ? include $cfgPath : [];
    if (!is_array($incoming)) {
        fwrite(STDERR, "invalid source config\n");
        exit(1);
    }
    $keepPassword = '';
    if (is_array($existing) && !empty($existing['password'])) {
        $keepPassword = (string) $existing['password'];
    }
    $incoming['password'] = $keepPassword !== ''
        ? $keepPassword
        : (string) (getenv('A7_TL_PHONE_MASK_PASSWORD') ?: '');
    @copy($cfgPath, $cfgPath . '.bak.' . date('YmdHis'));
    $export = "<?php\nreturn " . var_export($incoming, true) . ";\n";
    file_put_contents($cfgPath, $export);
    echo "Wrote config (password " . ($incoming['password'] !== '' ? 'preserved' : 'empty-set-env') . ")\n";
}

$init = file_get_contents($initPath);
if ($init === false) {
    fwrite(STDERR, "Cannot read init.php\n");
    exit(1);
}

$old = <<<'OLD'
// A7_TL_PHONE_MASK: Team leads see masked phones (last 4 hidden) on CRM + Owner Data.
if (function_exists('AddEventHandler')) {
    AddEventHandler('main', 'OnProlog', static function () {
        global $APPLICATION, $USER;
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $onOwner = strpos($uri, '/crm/type/1042/') === 0;
        $onLead = strpos($uri, '/crm/lead/') === 0;
        if (!$onOwner && !$onLead) {
            return;
        }
OLD;

$new = <<<'NEW'
// A7_TL_PHONE_MASK: Team leads see masked phones (last 4 hidden) on CRM + Owner Data + Telephony.
if (function_exists('AddEventHandler')) {
    AddEventHandler('main', 'OnProlog', static function () {
        global $APPLICATION, $USER;
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $onOwner = strpos($uri, '/crm/type/1042/') === 0;
        $onLead = strpos($uri, '/crm/lead/') === 0;
        $onTelephony = stripos($uri, 'telephony/detail') !== false
            || stripos($uri, 'voximplant.statistic.detail') !== false
            || stripos($uri, '/telephony/') !== false;
        if (!$onOwner && !$onLead && !$onTelephony) {
            return;
        }
NEW;

if (str_contains($init, '$onTelephony')) {
    echo "init.php already has telephony gate\n";
} elseif (str_contains($init, $old)) {
    @copy($initPath, $initPath . '.bak.a7mask.' . date('YmdHis'));
    file_put_contents($initPath, str_replace($old, $new, $init));
    echo "Patched init.php for telephony\n";
} else {
    echo "WARN: could not patch init.php automatically\n";
}

$loaded = include $cfgPath;
$pass = (string) ($loaded['password'] ?? '');
$leaders = array_keys($loaded['leaders'] ?? []);
echo 'password_configured=' . ($pass !== '' ? 'YES' : 'NO') . "\n";
echo 'leaders=' . json_encode($leaders) . "\n";
echo 'js_exists=' . (is_readable($jsPath) ? 'Y' : 'N') . ' size=' . (is_readable($jsPath) ? filesize($jsPath) : 0) . "\n";
echo 'init_has_telephony=' . (str_contains((string) file_get_contents($initPath), '$onTelephony') ? 'Y' : 'N') . "\n";
echo "DONE\n";
