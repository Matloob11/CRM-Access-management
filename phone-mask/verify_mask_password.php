#!/usr/bin/env php
<?php
/** Confirm mask allow-list and that an unlock password is configured (value not printed). */
declare(strict_types=1);
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require '/home/bitrix/www/bitrix/modules/main/include/prolog_before.php';

global $USER;
$USER->Authorize(2560);

$cfg = include '/home/bitrix/www/bitrix/php_interface/a7_tl_phone_mask.config.php';
$expected = (string) ($cfg['password'] ?? '');
echo 'user=' . $USER->GetID() . ' isMaskedUser=' . (isset($cfg['leaders'][2560]) ? 'Y' : 'N') . "\n";
echo 'password_configured=' . ($expected !== '' ? 'Y' : 'N') . "\n";
echo 'masked_count=' . count($cfg['leaders'] ?? []) . "\n";
echo 'agent2344_isMasked=' . (isset($cfg['leaders'][2344]) ? 'Y' : 'N') . "\n";
echo ($expected !== '' && isset($cfg['leaders'][2560]) && !isset($cfg['leaders'][2344]))
    ? "PASS\n"
    : "FAIL\n";
