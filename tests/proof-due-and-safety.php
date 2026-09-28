<?php
/**
 * Proof harness for CHIP-for-Perfex-CRM.
 *
 * Loads the SHIPPED Chip_gateway.php (with minimal Perfex stubs) and drives its
 * real methods, rather than re-implementing the logic being tested.
 *
 * Run: php tests/proof-due-and-safety.php
 */

define('BASEPATH', __DIR__);
define('CHIP_MODULE_NAME', 'chip');
define('APP_MODULES_PATH', '/tmp/no-such-modules-path/');
define('APPPATH', '/tmp/no-such-apppath/');
define('DB_PREFIX', '');

if (!function_exists('db_prefix')) {
    function db_prefix() { return 'tbl'; }
}
if (!function_exists('get_option')) {
    function get_option($n) { return $GLOBALS['__options'][$n] ?? false; }
}
if (!function_exists('update_option')) {
    function update_option($n, $v, $a = true) { $GLOBALS['__options'][$n] = $v; return true; }
}
if (!function_exists('site_url')) {
    function site_url($p = '') { return 'https://example.test/' . ltrim($p, '/'); }
}
if (!function_exists('set_alert')) {
    function set_alert($t, $m) { $GLOBALS['__alerts'][] = [$t, $m]; }
}
if (!function_exists('redirect')) {
    function redirect($u) { $GLOBALS['__redirected'] = $u; }
}
if (!function_exists('get_country_short_name')) {
    function get_country_short_name($c) { return $c; }
}

class DateTimeZoneStub {}

/** Minimal stand-in for Perfex's App_gateway. */
class App_gateway
{
    public $ci;
    protected $id = 'chip';

    public function getId() { return $this->id; }

    public function getSetting($name)
    {
        return trim((string) ($GLOBALS['__settings'][$name] ?? ''));
    }
}

require_once __DIR__ . '/../libraries/Chip_api.php';
require_once __DIR__ . '/../libraries/Chip_gateway.php';

// ── harness ───────────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
$failures = [];

function check($label, $condition, $detail = '')
{
    global $pass, $fail, $failures;

    if ($condition) {
        $pass++;
        echo "  PASS  $label\n";

        return;
    }

    $fail++;
    $failures[] = $label . ($detail !== '' ? " ($detail)" : '');
    echo "  FAIL  $label" . ($detail !== '' ? "  -> $detail" : '') . "\n";
}

/**
 * The pre-fix implementation, copied verbatim from origin/main so the new
 * tests can be shown to actually bite on it.
 *
 * It reads the `due_strict` yes/no toggle instead of `due_strict_timing`, so
 * the merchant's configured window is ignored entirely, and when the toggle is
 * on ('1') the purchase is given exactly one minute to be paid.
 */
function legacy_due($due_strict_setting)
{
    $due_strict_timing = preg_replace('/[^0-9]/', '', $due_strict_setting);
    if (empty($due_strict_timing)) {
        $due_strict_timing = 60;
    }

    return time() + (abs((int) $due_strict_timing) * 60);
}

$gw = (new ReflectionClass('Chip_gateway'))->newInstanceWithoutConstructor();

// On the pre-fix code the method does not exist at all. Report that as a hard
// failure rather than letting ReflectionException abort the whole run, so this
// suite gives a clean RED against the original code.
if (!method_exists('Chip_gateway', 'resolve_due_timestamp')) {
    echo "== 0. resolve_due_timestamp() present?\n";
    check('Chip_gateway::resolve_due_timestamp() exists', false, 'method missing — the due fix is not applied');

    echo "\n" . str_repeat('-', 62) . "\n";
    echo sprintf("  %d passed, %d failed\n", $pass, $fail);
    echo "  result: RED (fix not present)\n";
    echo str_repeat('-', 62) . "\n";

    exit(1);
}

$m  = new ReflectionMethod('Chip_gateway', 'resolve_due_timestamp');
$m->setAccessible(true);

echo "== 1. resolve_due_timestamp() — the shipped method ==\n";

check('empty string means "no due limit"', $m->invoke($gw, '') === null, 'expected null');
check('null means "no due limit"', $m->invoke($gw, null) === null, 'expected null');
check('false means "no due limit"', $m->invoke($gw, false) === null, 'expected null');
check('"0" means "no due limit"', $m->invoke($gw, '0') === null, 'expected null');
check('"0 minutes" means "no due limit"', $m->invoke($gw, '0 minutes') === null, 'expected null');

$sixty = $m->invoke($gw, 60);
check(
    '60 minutes is ~1 hour ahead',
    is_int($sixty) && abs(($sixty - time()) - 3600) < 5,
    'got ' . var_export($sixty, true)
);

$day = $m->invoke($gw, 1440);
check(
    '1440 minutes is ~24 hours ahead',
    is_int($day) && abs(($day - time()) - 86400) < 5,
    'got ' . var_export($day, true)
);

check(
    'a negative value is treated as its magnitude',
    is_int($m->invoke($gw, '-30')) && abs(($m->invoke($gw, '-30') - time()) - 1800) < 5,
    'expected ~30 minutes ahead'
);

// The shipping default for the setting is 60, and getSetting() returns a
// trimmed STRING, so the string path must work.
check(
    'the setting is read as the string Perfex returns',
    abs(($m->invoke($gw, '60') - time()) - 3600) < 5,
    'got ' . round(($m->invoke($gw, '60') - time()) / 60) . ' minutes'
);

echo "\n== 2. the shipped method must fix the real legacy defects ==\n";

$legacy_minutes = function ($setting) {
    return (int) round((legacy_due($setting) - time()) / 60);
};

// Defect 1: the toggle drove the window. With the toggle ON the customer got
// one minute, whatever the merchant configured.
check(
    'legacy gave a 1-minute window when due_strict was on',
    $legacy_minutes('1') === 1,
    'legacy gave ' . $legacy_minutes('1') . ' minutes'
);
check(
    'shipped ignores the toggle and honours a 1440-minute config',
    abs(($m->invoke($gw, '1440') - time()) - 86400) < 5,
    'shipped gave ' . round(($m->invoke($gw, '1440') - time()) / 60) . ' minutes'
);

// Defect 2: with the toggle off, the merchant's configured timing was ignored
// and replaced with a hardcoded 60 minutes.
check(
    'legacy replaced the configured 1440 with a hardcoded 60',
    $legacy_minutes('0') === 60,
    'legacy gave ' . $legacy_minutes('0') . ' minutes'
);

// Defect 3: a blank timing still produced a due in the past once the window
// elapsed; the shipped method omits the parameter instead.
check(
    'shipped omits due (rather than sending +60) when the timing is blank',
    $m->invoke($gw, '') === null,
    'got ' . var_export($m->invoke($gw, ''), true)
);

echo "\n== 4. Chip_api error handling — against the real API is done separately;\n";
echo "      here the pure parsing/response-shape behaviour ==\n";

$api = new Chip_api(['x', 'y']);
check('a fresh instance reports no error', $api->get_last_error() === null, 'expected null');

$describe = new ReflectionMethod('Chip_api', 'describe_error');
$describe->setAccessible(true);

check(
    'describe_error flattens __all__ messages',
    $describe->invoke($api, ['__all__' => [['message' => 'Incorrect secret_key', 'code' => 'authentication_failed']]]) === 'Incorrect secret_key',
    (string) var_export($describe->invoke($api, ['__all__' => [['message' => 'Incorrect secret_key']]]), true)
);

check(
    'describe_error reads plain string messages',
    $describe->invoke($api, ['field' => ['oops']]) === 'oops'
);

check(
    'describe_error reports every field, not just the first',
    $describe->invoke($api, ['a' => [['message' => 'first']], 'b' => [['message' => 'second']]]) === 'first second',
    (string) var_export($describe->invoke($api, ['a' => [['message' => 'first']], 'b' => [['message' => 'second']]]), true)
);

check(
    'describe_error returns null for a non-error body',
    $describe->invoke($api, ['errors' => [], '__all__' => []]) === null
);

echo "\n== 5. the whitelist valid-list must accept the modern keys ==\n";

$src = file_get_contents(__DIR__ . '/../libraries/Chip_gateway.php');
preg_match('/if \(!in_array\(\$payment_method_whitelist\[\$i\], \[(.*?)\]\)\)/s', $src, $mm);
$valid = array_map(
    function ($v) { return trim($v, " '"); },
    explode(',', $mm[1] ?? '')
);

foreach (['dnqr', 'duitnow_qr', 'shopee_pay', 'razer_shopeepay', 'mpgs_google_pay', 'mpgs_apple_pay', 'fpx'] as $pm) {
    check("valid list accepts '$pm'", in_array($pm, $valid, true), 'found: ' . implode(',', $valid));
}

echo "\n== 6. no secrets or non-2xx blindness left in the transport ==\n";

$api_src = file_get_contents(__DIR__ . '/../libraries/Chip_api.php');

check(
    'CURLOPT_SSL_VERIFYPEER is enabled',
    preg_match('/CURLOPT_SSL_VERIFYPEER,\s*1\b/', $api_src) === 1,
    'expected CURLOPT_SSL_VERIFYPEER, 1'
);
check(
    'a non-2xx status is rejected',
    strpos($api_src, '$status < 200 || $status >= 300') !== false
);
check(
    'CURLOPT_SSL_VERIFYHOST is pinned to 2',
    preg_match('/CURLOPT_SSL_VERIFYHOST,\s*2\b/', $api_src) === 1
);

$gw_src = file_get_contents(__DIR__ . '/../libraries/Chip_gateway.php');
check(
    'creator_agent reads the version header',
    strpos($gw_src, "'creator_agent' => 'PerfexCRM: ' . \$this->module_version()") !== false
);

$ctrl_src = file_get_contents(__DIR__ . '/../controllers/Chip.php');
check(
    'webhook rejects unparseable payloads before reading status',
    strpos($ctrl_src, "!is_array(\$payment) || !isset(\$payment['status'])" ) !== false
);

echo "\n" . str_repeat('-', 62) . "\n";
echo sprintf("  %d passed, %d failed\n", $pass, $fail);
if ($failures) {
    echo "  failures:\n";
    foreach ($failures as $f) {
        echo "    - $f\n";
    }
}
echo str_repeat('-', 62) . "\n";

exit($fail === 0 ? 0 : 1);
