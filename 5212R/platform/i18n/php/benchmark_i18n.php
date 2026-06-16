#!/home/solarspeed/admserv-php/bin/php
<?php

$root = '/home/devel/BlueOnyx/BlueOnyx/5212R/platform/alpine.mod/ci4/app/Libraries';
require $root . '/I18n.php';

const DEFAULT_LOOPS = 20000;
const TEST_DOMAIN = 'base-alpine';
const TEST_LOCALE = 'de_DE';

$ops = [
    ['type' => 'get', 'tag' => 'login', 'domain' => TEST_DOMAIN, 'vars' => []],
    ['type' => 'get', 'tag' => 'loginPageTitle', 'domain' => TEST_DOMAIN, 'vars' => []],
    ['type' => 'get', 'tag' => 'loginPageUsername', 'domain' => TEST_DOMAIN, 'vars' => []],
    ['type' => 'get', 'tag' => 'loginPagePassword', 'domain' => TEST_DOMAIN, 'vars' => []],
    ['type' => 'get', 'tag' => 'controlpanel', 'domain' => TEST_DOMAIN, 'vars' => []],
    ['type' => 'get', 'tag' => 'documentation', 'domain' => TEST_DOMAIN, 'vars' => []],
    ['type' => 'property', 'property' => 'decimalSeparator', 'domain' => 'palette', 'language' => TEST_LOCALE],
    ['type' => 'property', 'property' => 'thousandsSeparator', 'domain' => 'palette', 'language' => TEST_LOCALE],
    ['type' => 'interpolate', 'text' => '[[base-alpine.login]] [[VAR.hostname]]', 'vars' => ['hostname' => 'blueonyx.local']],
];

$classOps = array_values(array_filter($ops, static function (array $op): bool {
    return $op['type'] !== 'property';
}));

$pageTags = [
    'login',
    'loginPageTitle',
    'loginPageUsername',
    'loginPagePassword',
    'loginPageLogin',
    'loginPageSecurity',
    'loginPageRevelPWDtxt',
    'loginAuthFailed',
    'loginOkMessage',
    'loginExpiredMessage',
    'loginMissingCookies',
    'loginNoCssMessage',
    'loginNoJsMessage',
    'loginPageUnameNotPW',
    'controlpanel',
    'controlpanelDescription',
    'documentation',
    'documentation_help',
    'osName',
    'osVendor',
];

function benchmark_page_gets(string $label, I18n $runner, array $tags, int $calls): array
{
    $start = hrtime(true);

    for ($i = 0; $i < $calls; $i++) {
        $runner->get($tags[$i % count($tags)], TEST_DOMAIN, []);
    }

    $elapsedNs = hrtime(true) - $start;
    $elapsedSec = $elapsedNs / 1000000000;

    return [
        'label' => $label,
        'loops' => $calls,
        'seconds' => $elapsedSec,
        'ops_per_sec' => $calls / max($elapsedSec, 0.000001),
        'usec_per_op' => ($elapsedSec * 1000000) / max($calls, 1),
    ];
}

function benchmark_runner(string $label, object $runner, array $ops, int $loops): array
{
    $start = hrtime(true);

    for ($i = 0; $i < $loops; $i++) {
        $op = $ops[$i % count($ops)];

        switch ($op['type']) {
            case 'get':
                $runner->i18n_get($op['tag'], $op['domain'], $op['vars']);
                break;
            case 'property':
                $runner->i18n_get_property($op['property'], $op['domain'], $op['language']);
                break;
            case 'interpolate':
                $runner->i18n_interpolate($op['text'], $op['vars']);
                break;
        }
    }

    $elapsedNs = hrtime(true) - $start;
    $elapsedSec = $elapsedNs / 1000000000;

    return [
        'label' => $label,
        'loops' => $loops,
        'seconds' => $elapsedSec,
        'ops_per_sec' => $loops / max($elapsedSec, 0.000001),
        'usec_per_op' => ($elapsedSec * 1000000) / max($loops, 1),
    ];
}

function benchmark_i18n_class(string $label, I18n $runner, array $ops, int $loops): array
{
    $start = hrtime(true);

    for ($i = 0; $i < $loops; $i++) {
        $op = $ops[$i % count($ops)];

        switch ($op['type']) {
            case 'get':
                $runner->get($op['tag'], $op['domain'], $op['vars']);
                break;
            case 'property':
                $runner->getProperty($op['property'], $op['domain'], $op['language']);
                break;
            case 'interpolate':
                $runner->interpolate($op['text'], $op['vars']);
                break;
        }
    }

    $elapsedNs = hrtime(true) - $start;
    $elapsedSec = $elapsedNs / 1000000000;

    return [
        'label' => $label,
        'loops' => $loops,
        'seconds' => $elapsedSec,
        'ops_per_sec' => $loops / max($elapsedSec, 0.000001),
        'usec_per_op' => ($elapsedSec * 1000000) / max($loops, 1),
    ];
}

function print_result(array $result): void
{
    printf(
        "%-18s  %8d ops  %8.4f s  %10.2f ops/s  %8.2f usec/op\n",
        $result['label'],
        $result['loops'],
        $result['seconds'],
        $result['ops_per_sec'],
        $result['usec_per_op']
    );
}

$loops = isset($argv[1]) ? max(1, (int) $argv[1]) : DEFAULT_LOOPS;

echo "BlueOnyx i18n benchmark\n";
echo "Locale: " . TEST_LOCALE . "\n";
echo "Domain mix: " . TEST_DOMAIN . " + palette\n";
echo "Loops per runner: " . $loops . "\n\n";

$native = new I18nNative();
$native->i18n_new(TEST_DOMAIN, TEST_LOCALE);

$results = [];

echo "Low-level wrappers\n";
if (function_exists('i18n_new')) {
    $extension = new I18nExtension();
    $extension->i18n_new(TEST_DOMAIN, TEST_LOCALE);

    benchmark_runner('warmup-extension', $extension, $ops, 2000);
    $results[] = benchmark_runner('i18n.so', $extension, $ops, $loops);
} else {
    echo "i18n.so is not loaded; extension benchmark skipped.\n";
}

benchmark_runner('warmup-fallback', $native, $ops, 1000);
$results[] = benchmark_runner('php-fallback', $native, $ops, $loops);

foreach ($results as $result) {
    print_result($result);
}

if (count($results) === 2) {
    $speedup = $results[0]['seconds'] > 0 ? ($results[1]['seconds'] / $results[0]['seconds']) : 0.0;
    printf("\nSpeedup: %.2fx faster with i18n.so\n", $speedup);
}

echo "\nHigh-level I18n.php\n";
$classResults = [];

if (function_exists('i18n_new')) {
    $i18nExtension = new I18n(TEST_DOMAIN, TEST_LOCALE);
    benchmark_i18n_class('warmup-I18n+so', $i18nExtension, $classOps, 2000);
    $classResults[] = benchmark_i18n_class('I18n + i18n.so', $i18nExtension, $classOps, $loops);
}

$i18nFallback = new I18n(TEST_DOMAIN, TEST_LOCALE);
$i18nFallback->setNative(true);
$i18nFallback->i18nNative = new I18nNative();
$i18nFallback->i18nNative->i18n_new(TEST_DOMAIN, TEST_LOCALE);
$i18nFallback->handle = 0;

benchmark_i18n_class('warmup-I18n+php', $i18nFallback, $classOps, 1000);
$classResults[] = benchmark_i18n_class('I18n + fallback', $i18nFallback, $classOps, $loops);

foreach ($classResults as $result) {
    print_result($result);
}

if (count($classResults) === 2) {
    $speedup = $classResults[0]['seconds'] > 0 ? ($classResults[1]['seconds'] / $classResults[0]['seconds']) : 0.0;
    printf("\nI18n.php speedup: %.2fx faster with i18n.so\n", $speedup);
}

echo "\nPage-style 200 get() calls\n";
$pageResults = [];

if (function_exists('i18n_new')) {
    $pageExtension = new I18n(TEST_DOMAIN, TEST_LOCALE);
    benchmark_page_gets('warmup-page+so', $pageExtension, $pageTags, 200);
    $pageResults[] = benchmark_page_gets('page + i18n.so', $pageExtension, $pageTags, 200);
}

$pageFallback = new I18n(TEST_DOMAIN, TEST_LOCALE);
$pageFallback->setNative(true);
$pageFallback->i18nNative = new I18nNative();
$pageFallback->i18nNative->i18n_new(TEST_DOMAIN, TEST_LOCALE);
$pageFallback->handle = 0;

benchmark_page_gets('warmup-page+php', $pageFallback, $pageTags, 200);
$pageResults[] = benchmark_page_gets('page + fallback', $pageFallback, $pageTags, 200);

foreach ($pageResults as $result) {
    print_result($result);
}

if (count($pageResults) === 2) {
    $speedup = $pageResults[0]['seconds'] > 0 ? ($pageResults[1]['seconds'] / $pageResults[0]['seconds']) : 0.0;
    printf("\nPage speedup: %.2fx faster with i18n.so\n", $speedup);
}
