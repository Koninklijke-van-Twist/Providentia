<?php
/**
 * Voorbeeld voor web/auth.php. Dit bestand niet als auth.php deployen en niet includen.
 * auth.php blijft buiten git; deze code schrijft auth.php nooit.
 *
 * Mímir eerst, en de BC-blok blijft de automatische fallback als Mímir uitvalt:
 *   $mimirApi  = 'mimir_…';  // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *
 * Met $mimirApi gezet probeert Providentia eerst Mímir en valt terug op de BC-variabelen hieronder.
 * Zonder $mimirApi wordt alleen de BC-blok gebruikt.
 * Laat $baseUrl, $environment, $auth_list en $auth naast $mimirApi staan.
 */
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

// --- Mímir ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (directe route, én fallback als Mímir faalt) ---
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
];
$auth = $auth_list['Production'];
