<?php

/**
 * Constants
 */
const PROVIDENTIA_PLANNINGS_TTL = 600;

const PROVIDENTIA_PLANNINGS_SELECT = [
    'Warning',
    'No',
    'Action_Message',
    'Due_Date',
    'Starting_Time',
    'Ending_Time',
    'Description',
    'Original_Quantity',
    'Quantity',
    'Reserved_Quantity',
    'Unit_of_Measure_Code',
    'Replenishment_System',
    'Vendor_No',
    'Line_No',
];

/**
 * Functies
 */
function providentia_discover_companies(): array
{
    try {
        $result = auth_discover_companies_across_active_environments(300);
        $companies = is_array($result['companies'] ?? null) ? $result['companies'] : [];
    } catch (Throwable $error) {
        $companies = [];
    }

    if ($companies === []) {
        $companies = [
            'Koninklijke van Twist',
            'Hunter van Twist',
            'KVT Gas',
        ];
    }

    return $companies;
}

function providentia_company_entity_url_with_query(string $company, string $entitySet, array $query, ?string $environment = null): string
{
    global $baseUrl;

    $companyName = trim($company);
    if ($companyName === '') {
        throw new RuntimeException('Geen bedrijf geselecteerd.');
    }

    $targetEnvironment = trim((string) ($environment ?? ''));
    if ($targetEnvironment === '') {
        $targetEnvironment = auth_get_environment_for_company($companyName, 300);
    }

    if ($targetEnvironment === '') {
        throw new RuntimeException('Geen environment beschikbaar.');
    }

    $base = trim((string) ($baseUrl ?? ''));
    if ($base === '') {
        throw new RuntimeException('baseUrl ontbreekt in auth.php.');
    }

    $safeCompany = str_replace("'", "''", $companyName);
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($base, '/') . '/' . rawurlencode($targetEnvironment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function providentia_decimal(mixed $value): float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    $text = trim((string) $value);
    if ($text === '') {
        return 0.0;
    }

    $text = str_replace(',', '.', $text);
    return is_numeric($text) ? (float) $text : 0.0;
}

function providentia_te_bestellen_quantity(array $row): float
{
    $original = providentia_decimal($row['Original_Quantity'] ?? 0);
    $quantity = providentia_decimal($row['Quantity'] ?? 0);
    $reserved = providentia_decimal($row['Reserved_Quantity'] ?? 0);

    return max(0.0, $original - $quantity - $reserved);
}

function providentia_format_quantity(float $value): string
{
    if (abs($value - round($value)) < 0.00001) {
        return (string) (int) round($value);
    }

    return rtrim(rtrim(number_format($value, 4, ',', ''), '0'), ',');
}

function providentia_quantity_with_unit(float $value, string $unit): string
{
    $display = providentia_format_quantity($value);
    if ($unit !== '') {
        $display .= ' ' . $unit;
    }

    return $display;
}

function providentia_normalize_plannings_row(array $row): array
{
    $originalQuantity = providentia_decimal($row['Original_Quantity'] ?? 0);
    $quantity = providentia_decimal($row['Quantity'] ?? 0);
    $reservedQuantity = providentia_decimal($row['Reserved_Quantity'] ?? 0);
    $teBestellen = max(0.0, $originalQuantity - $quantity - $reservedQuantity);
    $unit = trim((string) ($row['Unit_of_Measure_Code'] ?? ''));

    return [
        'warning' => trim((string) ($row['Warning'] ?? '')),
        'no' => trim((string) ($row['No'] ?? '')),
        'action_message' => trim((string) ($row['Action_Message'] ?? '')),
        'due_date' => trim((string) ($row['Due_Date'] ?? '')),
        'starting_time' => trim((string) ($row['Starting_Time'] ?? '')),
        'ending_time' => trim((string) ($row['Ending_Time'] ?? '')),
        'description' => trim((string) ($row['Description'] ?? '')),
        'original_quantity' => $originalQuantity,
        'quantity' => $quantity,
        'reserved_quantity' => $reservedQuantity,
        'unit_of_measure_code' => $unit,
        'te_bestellen' => $teBestellen,
        'te_bestellen_display' => providentia_quantity_with_unit($teBestellen, $unit),
        'replenishment_system' => trim((string) ($row['Replenishment_System'] ?? '')),
        'vendor_no' => trim((string) ($row['Vendor_No'] ?? '')),
        'line_no' => (int) ($row['Line_No'] ?? 0),
    ];
}

function providentia_fetch_planningsvoorstellen(string $company): array
{
    $companyName = trim($company);
    if ($companyName === '') {
        throw new RuntimeException('Kies een bedrijf.');
    }

    auth_set_current_company_context($companyName, 300);
    $auth = auth_get_auth_for_company($companyName, 300);

    $url = providentia_company_entity_url_with_query($companyName, 'Planningsvoorstellen', [
        '$select' => implode(',', PROVIDENTIA_PLANNINGS_SELECT),
    ]);

    $rows = odata_get_all($url, $auth, PROVIDENTIA_PLANNINGS_TTL);
    if (!is_array($rows)) {
        throw new RuntimeException('Ongeldige OData-respons voor planningsvoorstellen.');
    }

    $normalized = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $normalized[] = providentia_normalize_plannings_row($row);
    }

    usort($normalized, static function (array $left, array $right): int {
        $dueCompare = strcmp((string) ($right['due_date'] ?? ''), (string) ($left['due_date'] ?? ''));
        if ($dueCompare !== 0) {
            return $dueCompare;
        }

        $noCompare = strnatcasecmp((string) ($left['no'] ?? ''), (string) ($right['no'] ?? ''));
        if ($noCompare !== 0) {
            return $noCompare;
        }

        return ((int) ($left['line_no'] ?? 0)) <=> ((int) ($right['line_no'] ?? 0));
    });

    return $normalized;
}

function providentia_plannings_payload(string $company): array
{
    $rows = providentia_fetch_planningsvoorstellen($company);

    return [
        'ok' => true,
        'company' => $company,
        'rows' => $rows,
        'count' => count($rows),
    ];
}
