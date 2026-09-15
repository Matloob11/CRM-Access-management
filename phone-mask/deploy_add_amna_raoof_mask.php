#!/usr/bin/env php
<?php
declare(strict_types=1);
$dst = '/home/bitrix/www/bitrix/php_interface/a7_tl_phone_mask.config.php';
$src = '/tmp/a7_tl_phone_mask.config.php';
if (!is_readable($src)) {
    fwrite(STDERR, "missing $src\n");
    exit(1);
}
@copy($dst, $dst . '.bak.' . date('YmdHis'));
if (!copy($src, $dst)) {
    fwrite(STDERR, "copy failed\n");
    exit(1);
}
$c = include $dst;
$ids = array_keys($c['leaders'] ?? []);
sort($ids);
echo 'password_configured=' . (!empty($c['password']) ? 'Y' : 'N') . "\n";
echo 'masked_users=' . json_encode($ids) . "\n";
echo 'has_amna=' . (isset($c['leaders'][2319]) ? 'Y' : 'N') . "\n";
echo 'has_raoof=' . (isset($c['leaders'][2191]) ? 'Y' : 'N') . "\n";
echo 'has_hina=' . (isset($c['leaders'][454]) ? 'Y' : 'N') . "\n";
echo 'has_sreya=' . (isset($c['leaders'][2257]) ? 'Y' : 'N') . "\n";
echo 'has_usman=' . (isset($c['leaders'][2628]) ? 'Y' : 'N') . "\n";
echo 'has_furqan=' . (isset($c['leaders'][2632]) ? 'Y' : 'N') . "\n";
echo "OK\n";
