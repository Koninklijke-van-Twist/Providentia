<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/providentia-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
// De test leest web/auth.php niet en schrijft het nooit.
$GLOBALS['odata_auth_php_path'] = sys_get_temp_dir() . '/providentia-fallback-no-auth.php';

$calls = [];
$GLOBALS['PROVIDENTIA_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match("#/ODataV4/Company\\(#", $url) !== 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';
require dirname(__DIR__) . '/web/auth_helper.php';
require dirname(__DIR__) . '/web/providentia_data.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Providentia] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$builtUrl = providentia_company_entity_url_with_query('KVT Gas', 'AppWerkorders', ['$select' => 'No'], 'Production');
if (strpos($builtUrl, "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?") !== 0) {
    fail('met baseUrl gezet blijft de pre-Mímir company-URL de BC-URL, kreeg: ' . $builtUrl);
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Companies?$select=Name') !== 0) {
    fail('company-fallback riep de pre-Mímir BC-company-URL niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser' || $calls[0]['ttl'] !== 300) {
    fail('company-fallback gebruikte niet de BC-credentials of de oude ttl: ' . json_encode($calls[0]));
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag gelogd worden, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Providentia] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

$beforeRelative = count($calls);
$relativeRows = odata_get_all("/Sandbox/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No", $auth, 33);
$relativeCall = $calls[$beforeRelative] ?? null;
$expectedRelative = "https://bc.example:7148/Sandbox/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No";
if (($relativeRows[0]['No'] ?? '') !== 'WO-1' || !is_array($relativeCall) || $relativeCall['url'] !== $expectedRelative || $relativeCall['ttl'] !== 33) {
    fail('relatieve Mímir-URL werd niet naar BC herschreven: ' . json_encode($relativeCall));
}
if (fallback_count() !== 1) {
    fail('open circuit mag niet opnieuw loggen, log=' . fallback_log());
}

odata_mimir_circuit_reset();
$parseError = null;
try {
    odata_mimir_fetch_all('https://bc.example/not-an-odata-url', 10);
    fail('onvertaalbare URL moet een fout geven');
} catch (Throwable $exception) {
    $parseError = $exception;
}
if (!$parseError instanceof Throwable || odata_mimir_circuit_open()) {
    fail('een fout van de caller mag het circuit niet openen');
}
if (fallback_count() !== 1) {
    fail('een fout van de caller mag geen fallback loggen');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

odata_mimir_circuit_reset();
unset(
    $GLOBALS['demeter_company_environment_map'],
    $GLOBALS['demeter_companies_by_environment'],
    $GLOBALS['demeter_active_environments']
);
$discovered = auth_discover_companies_via_mimir();
if (($discovered['map']['Koninklijke van Twist'] ?? '') !== 'Production' || ($discovered['companies'][0] ?? '') === '') {
    fail('auth-discovery viel niet terug op BC: ' . json_encode($discovered));
}
$planned = providentia_fetch_planningsvoorstellen('Koninklijke van Twist');
if (($planned[0]['no'] ?? '') !== 'WO-1') {
    fail('planningsvoorstellen viel niet terug op de directe BC-stub: ' . json_encode($planned));
}

$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
];
$auth = $auth_list['Production'];
$environment = 'Production';
$GLOBALS['demeter_company_environment_map'] = [
    'Hunter van Twist' => 'Sandbox',
    'KVT Gas' => 'Production',
];
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeCompanyEnv = count($calls);
$companyEnvRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 30);
if (($companyEnvRows[0]['No'] ?? '') !== 'WO-1') {
    fail('company-environment fallback gaf geen rijen');
}
$companyEnvCall = $calls[$beforeCompanyEnv] ?? null;
if (!is_array($companyEnvCall)
    || strpos((string) ($companyEnvCall['url'] ?? ''), "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?") !== 0
    || ($companyEnvCall['user'] ?? '') !== 'sandbox-user'
) {
    fail('query gebruikte niet het environment en de auth van het bedrijf: ' . json_encode($companyEnvCall));
}

odata_mimir_circuit_reset();
$beforeUrlEnv = count($calls);
$urlEnvRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    12
);
if (($urlEnvRows[0]['No'] ?? '') !== 'WO-1') {
    fail('URL-environment fallback gaf geen rijen');
}
$urlEnvCall = $calls[$beforeUrlEnv] ?? null;
if (!is_array($urlEnvCall)
    || ($urlEnvCall['url'] ?? '') !== "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No"
    || ($urlEnvCall['user'] ?? '') !== 'sandbox-user'
) {
    fail('URL-segment werd vervangen door het primaire environment: ' . json_encode($urlEnvCall));
}

odata_mimir_circuit_reset();
$beforeMapped = count($calls);
$mappedRows = odata_get_all(
    "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    12
);
if (($mappedRows[0]['No'] ?? '') !== 'WO-1') {
    fail('company-map fallback gaf geen rijen');
}
$mappedCall = $calls[$beforeMapped] ?? null;
if (!is_array($mappedCall)
    || strpos((string) ($mappedCall['url'] ?? ''), 'https://bc.example:7148/Sandbox/ODataV4/') !== 0
    || ($mappedCall['user'] ?? '') !== 'sandbox-user'
) {
    fail('placeholder-environment negeerde de company-map: ' . json_encode($mappedCall));
}
$cacheKey = build_cache_key(
    "https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders",
    $auth_list['Sandbox']
);
$cacheParts = explode('|', $cacheKey);
$cacheEnv = (string) ($cacheParts[count($cacheParts) - 1] ?? '');
if ($cacheEnv !== 'Sandbox') {
    fail('cache-key gebruikt niet de BC-environment van het bedrijf: ' . $cacheKey);
}

odata_mimir_circuit_reset();
$beforeSecondEnv = count($calls);
odata_mimir_list_companies(null);
$sawProduction = false;
$sawSandbox = false;
for ($i = $beforeSecondEnv; $i < count($calls); $i++) {
    $call = $calls[$i];
    if (strpos((string) ($call['url'] ?? ''), 'https://bc.example:7148/Production/ODataV4/Companies?') === 0 && ($call['user'] ?? '') === 'bcuser') {
        $sawProduction = true;
    }
    if (strpos((string) ($call['url'] ?? ''), 'https://bc.example:7148/Sandbox/ODataV4/Companies?') === 0 && ($call['user'] ?? '') === 'sandbox-user') {
        $sawSandbox = true;
    }
}
if (!$sawProduction || !$sawSandbox) {
    fail('company-lijst beperkte zich tot de primaire environment: ' . json_encode(array_slice($calls, $beforeSecondEnv)));
}
if (strpos(fallback_log(), 'sandbox-secret') !== false || strpos(fallback_log(), 'bc-secret') !== false) {
    fail('log bevat een geheim na company-environment fallback');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeEncode = count($calls);
$encodedRows = odata_get_all(
    "https://mimir.invalid/My%20Env/ODataV4/Company('Nobody%20BV')/AppWerkorders?\$select=No",
    $auth,
    9
);
$encodeCall = $calls[$beforeEncode] ?? null;
$expectedEncode = "https://bc.example:7148/My%20Env/ODataV4/Company('Nobody%20BV')/AppWerkorders?\$select=No";
if (($encodedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($encodeCall) || ($encodeCall['url'] ?? '') !== $expectedEncode || ($encodeCall['user'] ?? '') !== 'bcuser') {
    fail('environment-segment werd niet één keer geëncodeerd: ' . json_encode($encodeCall));
}

odata_mimir_circuit_reset();
$GLOBALS['demeter_company_environment_map']['Secret Co'] = 'OtherEnv';
$beforeKnown = count($calls);
$knownMiss = null;
try {
    odata_get_all(
        "https://mimir.invalid/mimir/ODataV4/Company('Secret%20Co')/AppWerkorders?\$select=No",
        $auth,
        9
    );
    fail('bekend bedrijf zonder auth_list-entry mag niet de primaire auth gebruiken');
} catch (Throwable $exception) {
    $knownMiss = $exception;
}
unset($GLOBALS['demeter_company_environment_map']['Secret Co']);
if (!$knownMiss instanceof Throwable || count($calls) !== $beforeKnown) {
    fail('bekend bedrijf zonder auth_list-entry gebruikte toch een andere auth');
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    fail('zonder BC-credentials kwam er geen fout terug');
}
if (strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$tmpAuth = sys_get_temp_dir() . '/providentia-auth-fallback-' . getmypid() . '.php';
file_put_contents($tmpAuth, <<<'PHP'
<?php
$baseUrl = 'https://loaded-bc.example:7148/';
$environment = 'LoadedEnv';
$auth_list = [
    'LoadedEnv' => ['mode' => 'basic', 'user' => 'loaded-user', 'pass' => 'loaded-secret'],
];
$auth = $auth_list['LoadedEnv'];
$base = 'from-file';
PHP);
$keptApi = $mimirApi;
$GLOBALS['base'] = 'keep-me';
unset($GLOBALS['baseUrl'], $GLOBALS['environment'], $GLOBALS['auth'], $GLOBALS['auth_list'], $GLOBALS['providentia_bc_auth_load_tried']);
$GLOBALS['odata_auth_php_path'] = $tmpAuth;
odata_load_bc_config_for_fallback();
$loadedBase = odata_bc_base_url();
$loadedUser = (string) ($GLOBALS['auth_list']['LoadedEnv']['user'] ?? '');
$loadedEnv = odata_bc_environment();
require_once $tmpAuth;
$baseAfterSecondInclude = odata_bc_base_url();
@unlink($tmpAuth);
$GLOBALS['odata_auth_php_path'] = sys_get_temp_dir() . '/providentia-fallback-no-auth.php';
if ($loadedBase !== 'https://loaded-bc.example:7148/') {
    fail('auth.php-variabelen bleven buiten $GLOBALS, base=' . var_export($loadedBase, true));
}
if ($loadedUser !== 'loaded-user' || $loadedEnv !== 'LoadedEnv') {
    fail('auth_list/environment uit auth.php zijn niet globaal: user=' . $loadedUser . ' env=' . var_export($loadedEnv, true));
}
if ($baseAfterSecondInclude !== 'https://loaded-bc.example:7148/') {
    fail('tweede require_once maakte de BC-globals weer leeg');
}
if (($GLOBALS['base'] ?? '') !== 'keep-me') {
    fail('een gezette global werd overschreven: ' . var_export($GLOBALS['base'] ?? null, true));
}
if ($mimirApi !== $keptApi) {
    fail('lazy load overschreef $mimirApi');
}
if (strpos(fallback_log(), 'loaded-secret') !== false) {
    fail('log bevat het wachtwoord uit auth.php');
}

echo "OK\n";
