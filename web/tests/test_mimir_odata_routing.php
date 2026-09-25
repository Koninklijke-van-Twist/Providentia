<?php

/**
 * OData-routing: Mímir als $mimirApi gezet is, BC als de key ontbreekt.
 * Run: php web/tests/test_mimir_odata_routing.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Includes/requires
 */
require_once dirname(__DIR__) . '/odata.php';
require_once dirname(__DIR__) . '/auth_helper.php';
require_once dirname(__DIR__) . '/providentia_data.php';

/**
 * Variabelen
 */
$failures = 0;
$mockPort = 18941;
$mockLog = sys_get_temp_dir() . '/providentia-mimir-mock.log';
$mockScript = sys_get_temp_dir() . '/providentia-mimir-mock.php';
$authPath = dirname(__DIR__) . '/auth.php';
$authBackup = null;

/**
 * Functies
 */
function test_assert(string $name, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$name}\n";
        return;
    }

    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function test_reset_discovery_cache(): void
{
    unset(
        $GLOBALS['demeter_company_environment_map'],
        $GLOBALS['demeter_companies_by_environment'],
        $GLOBALS['demeter_active_environments']
    );
}

function test_write_mock(): void
{
    global $mockScript, $mockLog;
    $log = var_export($mockLog, true);
    $php = <<<'PHP'
<?php
$log = LOG_PATH;
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents($log, json_encode([
    'uri' => $uri,
    'method' => $method,
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'authorization' => $authorization,
    'api_key' => (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''),
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');

if (str_contains($uri, '/mimir-dup/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Overlap BV', 'environment' => 'Production'],
        ['name' => 'Overlap BV', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Koninklijke van Twist', 'environment' => 'Production'],
        ['name' => "Van Twist's", 'environment' => 'Production'],
        ['name' => 'Hunter van Twist', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/query.php')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    echo json_encode(['value' => [[
        'No' => 'PRJ1',
        'Warning' => '',
        'company' => (string) ($body['company'] ?? ''),
        'table' => (string) ($body['table'] ?? ''),
        'select' => $body['select'] ?? [],
        'filter' => (string) ($body['filter'] ?? ''),
        'max_age' => $body['max_age'] ?? null,
    ]]]);
    exit;
}

$user = '';
if (str_starts_with($authorization, 'Basic ')) {
    $decoded = base64_decode(substr($authorization, 6), true);
    if (is_string($decoded) && str_contains($decoded, ':')) {
        $user = explode(':', $decoded, 2)[0];
    }
}
echo json_encode(['value' => [[
    'Name' => 'BC Company',
    'via' => 'bc',
    'user' => $user,
]]]);
PHP;
    file_put_contents($mockScript, str_replace('LOG_PATH', $log, $php));
}

/**
 * Zet auth.php terug. Verwijdert het bestand alleen als deze test het zelf heeft aangemaakt.
 */
function test_restore_auth_php(string $path, bool $existedBefore, ?string $backup, bool $written): void
{
    if (!$written) {
        return;
    }

    if ($existedBefore) {
        if (!is_string($backup)) {
            return;
        }
        file_put_contents($path, $backup);
        return;
    }

    @unlink($path);
}

function test_mock_requests(): array
{
    global $mockLog;
    if (!is_file($mockLog)) {
        return [];
    }
    $rows = [];
    foreach (file($mockLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return $rows;
}

/**
 * Page load
 */
test_assert('mimir uit zonder key', odata_mimir_enabled() === false);
test_assert(
    'default mimir base',
    odata_mimir_base_url() === 'https://sleutels.kvt.nl/mimir/api'
);

$restoreProbe = sys_get_temp_dir() . '/providentia-auth-restore-probe.php';
$restoreCreated = sys_get_temp_dir() . '/providentia-auth-restore-created.php';
file_put_contents($restoreProbe, "<?php\n\$marker = 'original';\n");
test_restore_auth_php($restoreProbe, true, "<?php\n\$marker = 'original';\n", false);
test_assert(
    'restore laat bestaand bestand met rust als de test niet schreef',
    is_file($restoreProbe) && str_contains((string) file_get_contents($restoreProbe), 'original')
);
file_put_contents($restoreProbe, "<?php\n\$marker = 'replaced';\n");
test_restore_auth_php($restoreProbe, true, "<?php\n\$marker = 'original';\n", true);
test_assert(
    'restore zet backup terug nadat de test schreef',
    is_file($restoreProbe) && str_contains((string) file_get_contents($restoreProbe), 'original')
);
@unlink($restoreProbe);
file_put_contents($restoreCreated, "<?php\n\$marker = 'created';\n");
test_restore_auth_php($restoreCreated, false, null, true);
test_assert('restore verwijdert alleen een door de test aangemaakt bestand', !is_file($restoreCreated));
file_put_contents($restoreCreated, "<?php\n\$marker = 'keep';\n");
test_restore_auth_php($restoreCreated, true, null, true);
test_assert(
    'restore wist geen bestaand bestand zonder leesbare backup',
    is_file($restoreCreated) && str_contains((string) file_get_contents($restoreCreated), 'keep')
);
@unlink($restoreCreated);

$baseUrl = 'https://bc.example';
$spaceUrl = providentia_company_entity_url_with_query(
    'Koninklijke van Twist',
    'Planningsvoorstellen',
    [
        '$select' => 'No,Description',
        '$filter' => "No eq 'PRJ1'",
    ],
    'Production'
);
$parsedSpace = odata_mimir_parse_entity_url($spaceUrl);
test_assert(
    'entity-URL met spatie in bedrijfsnaam',
    is_array($parsedSpace)
        && ($parsedSpace['company'] ?? '') === 'Koninklijke van Twist'
        && ($parsedSpace['entity'] ?? '') === 'Planningsvoorstellen'
        && ($parsedSpace['query']['$select'] ?? '') === 'No,Description'
        && ($parsedSpace['query']['$filter'] ?? '') === "No eq 'PRJ1'",
    json_encode($parsedSpace, JSON_UNESCAPED_UNICODE)
);
test_assert(
    'entity-URL is geen company-discovery',
    odata_mimir_parse_companies_url($spaceUrl) === null
);

$companiesUrl = 'https://bc.example/Production/ODataV4/Companies?$select=Name';
$parsedCompanies = odata_mimir_parse_companies_url($companiesUrl);
test_assert(
    'companies-URL levert environment',
    is_array($parsedCompanies) && ($parsedCompanies['environment'] ?? '') === 'Production',
    json_encode($parsedCompanies)
);
test_assert('companies-URL is geen entity', odata_mimir_parse_entity_url($companiesUrl) === null);

$threw = false;
try {
    auth_get_auth_for_environment('Production');
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'Geen auth-configuratie');
}
test_assert('BC-auth ontbreekt blijft exception zonder Mímir', $threw);

$threw = false;
try {
    auth_discover_companies_across_active_environments(30);
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'Geen actieve environments');
}
test_assert('company-discovery faalt snel zonder BC-config en zonder Mímir', $threw);

$baseUrl = '';
$threw = false;
try {
    providentia_company_entity_url_with_query('Koninklijke van Twist', 'Planningsvoorstellen', [], 'Production');
} catch (RuntimeException $error) {
    $threw = str_contains($error->getMessage(), 'baseUrl ontbreekt');
}
test_assert('URL-builder eist baseUrl zonder Mímir', $threw);

test_write_mock();
@unlink($mockLog);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    sys_get_temp_dir()
);
test_assert('mock-server start', is_resource($server));
usleep(200000);

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
$baseUrl = '';
unset($GLOBALS['auth_list'], $GLOBALS['environment'], $GLOBALS['auth']);
test_reset_discovery_cache();

$authExistedBefore = is_file($authPath);
$authBackup = null;
if ($authExistedBefore) {
    $authRaw = file_get_contents($authPath);
    $authBackup = is_string($authRaw) ? $authRaw : null;
}
$authWritten = false;

try {
    $apostropheUrl = providentia_company_entity_url_with_query(
        "Van Twist's",
        'Planningsvoorstellen',
        ['$select' => 'No,Description'],
        'Production'
    );
    $parsedApostrophe = odata_mimir_parse_entity_url($apostropheUrl);
    test_assert(
        'lege baseUrl en apostrof in bedrijfsnaam',
        is_array($parsedApostrophe)
            && ($parsedApostrophe['company'] ?? '') === "Van Twist's"
            && ($parsedApostrophe['entity'] ?? '') === 'Planningsvoorstellen'
            && ($parsedApostrophe['query']['$select'] ?? '') === 'No,Description',
        json_encode($parsedApostrophe, JSON_UNESCAPED_UNICODE)
    );

    $discovered = auth_discover_companies_across_active_environments(30);
    test_assert(
        'Mímir company-discovery zonder BC-creds',
        ($discovered['companies'] ?? []) === ['Hunter van Twist', 'Koninklijke van Twist', "Van Twist's"]
            && ($discovered['map']['Koninklijke van Twist'] ?? '') === 'Production'
            && ($discovered['map']['Hunter van Twist'] ?? '') === 'Sandbox'
            && ($discovered['primary_environment'] ?? '') === 'Production',
        json_encode($discovered, JSON_UNESCAPED_UNICODE)
    );

    $auth = auth_get_auth_for_environment('Production');
    test_assert('lege auth-sentinel in Mímir-modus', $auth === []);

    $context = auth_set_current_company_context('Hunter van Twist', 30);
    test_assert(
        'company-context zonder BC-auth',
        ($context['environment'] ?? '') === 'Sandbox' && ($context['auth'] ?? null) === []
    );

    $active = auth_get_active_environments();
    test_assert(
        'environments uit Mímir als auth_list ontbreekt',
        $active === ['Production', 'Sandbox'],
        json_encode($active)
    );
    unset($GLOBALS['demeter_active_environments']);
    $coldActive = auth_get_active_environments();
    test_assert(
        'koude environment-lijst komt gesorteerd uit discovery',
        $coldActive === ['Production', 'Sandbox'],
        json_encode($coldActive)
    );

    $savedMap = $GLOBALS['demeter_company_environment_map'] ?? null;
    $GLOBALS['demeter_company_environment_map'] = ['Koninklijke van Twist' => ''];
    $emptyEnvironmentThrew = false;
    try {
        providentia_company_entity_url_with_query('Koninklijke van Twist', 'Planningsvoorstellen', []);
    } catch (RuntimeException $error) {
        $emptyEnvironmentThrew = str_contains($error->getMessage(), 'Geen environment beschikbaar');
    }
    if (is_array($savedMap)) {
        $GLOBALS['demeter_company_environment_map'] = $savedMap;
    } else {
        unset($GLOBALS['demeter_company_environment_map']);
    }
    test_assert('leeg environment blijft een fout in Mímir-modus', $emptyEnvironmentThrew);

    unset($GLOBALS['baseUrl']);
    $urlWithoutBase = providentia_company_entity_url_with_query(
        'Koninklijke van Twist',
        'Planningsvoorstellen',
        ['$select' => implode(',', PROVIDENTIA_PLANNINGS_SELECT)],
        'Production'
    );
    test_assert(
        'URL-builder zonder baseUrl in Mímir-modus',
        str_contains($urlWithoutBase, '/Production/ODataV4/')
            && str_contains($urlWithoutBase, 'Planningsvoorstellen'),
        $urlWithoutBase
    );

    $beforeCache = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    $queryRows = odata_get_all($urlWithoutBase, [], PROVIDENTIA_PLANNINGS_TTL);
    $queryRow = $queryRows[0] ?? null;
    test_assert(
        'planningsvoorstellen-query via Mímir',
        is_array($queryRow)
            && ($queryRow['company'] ?? '') === 'Koninklijke van Twist'
            && ($queryRow['table'] ?? '') === 'Planningsvoorstellen'
            && ($queryRow['max_age'] ?? null) === PROVIDENTIA_PLANNINGS_TTL
            && in_array('No', $queryRow['select'] ?? [], true)
            && in_array('Warning', $queryRow['select'] ?? [], true),
        json_encode($queryRow, JSON_UNESCAPED_UNICODE)
    );

    $rows = providentia_fetch_planningsvoorstellen('Koninklijke van Twist');
    test_assert(
        'providentia_fetch_planningsvoorstellen zonder BC-creds',
        is_array($rows[0] ?? null) && ($rows[0]['no'] ?? '') === 'PRJ1',
        json_encode($rows, JSON_UNESCAPED_UNICODE)
    );
    $afterCache = glob(dirname(__DIR__) . '/cache/odata/*.json') ?: [];
    test_assert('Mímir slaat Providentia-filecache over', count($afterCache) === count($beforeCache));

    $companyRows = odata_get_all('https://bc.example/Sandbox/ODataV4/Company?$select=Name', [], 30);
    $companyNames = array_map(static function (array $row): string {
        return (string) ($row['Name'] ?? '');
    }, $companyRows);
    test_assert(
        'company-discovery-URL gaat naar Mímir, niet naar BC-host',
        $companyNames === ['Hunter van Twist'],
        json_encode($companyNames, JSON_UNESCAPED_UNICODE)
    );

    $requests = test_mock_requests();
    $hitBcHost = false;
    $sawMimirUa = false;
    $sawApiKey = false;
    foreach ($requests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), 'bc.example')) {
            $hitBcHost = true;
        }
        if (($request['ua'] ?? '') === 'Providentia-MimirClient/1.0' && str_contains((string) ($request['uri'] ?? ''), '/mimir/api/')) {
            $sawMimirUa = true;
        }
        if (($request['api_key'] ?? '') === 'mimir_test_key' && str_starts_with((string) ($request['authorization'] ?? ''), 'Bearer ')) {
            $sawApiKey = true;
        }
    }
    test_assert('geen request naar de BC-host', $hitBcHost === false);
    test_assert('Mímir-client user-agent', $sawMimirUa);
    test_assert('Mímir API-key gaat mee als Bearer en X-API-Key', $sawApiKey);

    test_reset_discovery_cache();
    $mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir-dup/api';
    $overlapThrew = false;
    try {
        auth_discover_companies_across_active_environments(30);
    } catch (RuntimeException $error) {
        $overlapThrew = str_contains($error->getMessage(), 'Bedrijfsnaam-overlap');
    }
    test_assert('overlap tussen Mímir-environments blijft een fout', $overlapThrew);

    $mimirApi = '';
    $baseUrl = 'http://127.0.0.1:' . $mockPort;
    $environment = 'Production';
    $auth_list = [
        'Production' => [
            'mode' => 'basic',
            'user' => 'bcuser',
            'pass' => 'bcpass',
        ],
    ];
    $auth = $auth_list['Production'];
    if ($authExistedBefore && !is_string($authBackup)) {
        throw new RuntimeException('Bestaande auth.php kon niet worden gelezen; test wijzigt het bestand niet.');
    }
    $authWritten = true;
    file_put_contents($authPath, "<?php\n\$baseUrl = " . var_export($baseUrl, true) . ";\n\$environment = 'Production';\n\$auth_list = " . var_export($auth_list, true) . ";\n\$mimirApi = '';\n");
    test_reset_discovery_cache();
    @unlink($mockLog);

    test_assert('Mímir uit na lege key', odata_mimir_enabled() === false);
    $bcRows = odata_get_all($baseUrl . '/Production/ODataV4/Companies?$select=Name', $auth, 30);
    test_assert(
        'zonder Mímir blijft BC-fetch werken',
        is_array($bcRows[0] ?? null) && ($bcRows[0]['via'] ?? '') === 'bc' && ($bcRows[0]['user'] ?? '') === 'bcuser',
        json_encode($bcRows)
    );
    $bcRequests = test_mock_requests();
    $bcHitMimir = false;
    foreach ($bcRequests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), '/mimir/')) {
            $bcHitMimir = true;
        }
    }
    test_assert('BC-fetch raakt Mímir niet', $bcHitMimir === false);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    test_restore_auth_php($authPath, $authExistedBefore, $authBackup, $authWritten);
    @unlink($mockScript);
    @unlink($mockLog);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "all mimir routing tests passed\n";
exit(0);
