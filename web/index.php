<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/providentia_data.php';

/**
 * Functies
 */
function providentia_action_is(string $expected): bool
{
    return (string) ($_GET['action'] ?? '') === $expected;
}

function providentia_selected_company(array $companies): string
{
    $requested = trim((string) ($_GET['company'] ?? ''));
    if ($requested !== '' && in_array($requested, $companies, true)) {
        return $requested;
    }

    return (string) ($companies[0] ?? '');
}

function providentia_send_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function providentia_runtime_error_payload(Throwable $error, string $message, int $statusCode = 500): void
{
    providentia_send_json([
        'ok' => false,
        'error' => $message,
        'details' => $error->getMessage(),
    ], $statusCode);
}

/**
 * Page load
 */
$companies = providentia_discover_companies();
$selectedCompany = providentia_selected_company($companies);
$cacheWidget = injectTimerHtml([
    'title' => 'OData cache',
    'label' => 'Cache',
    'css' => <<<'CSS'
{{root}} {
	position: relative;
	display: block;
	margin-top: 12px;
}

{{root}} .odata-cache-widget {
	position: static;
	margin-left: auto;
}

{{root}} .odata-cache-popout {
	left: 0;
	right: 0;
	width: min(760px, calc(100vw - 32px));
	margin-top: 10px;
}
CSS,
]);

if (providentia_action_is('planningsvoorstellen')) {
    $company = trim((string) ($_POST['company'] ?? $_GET['company'] ?? ''));
    if ($company === '' || !in_array($company, $companies, true)) {
        providentia_send_json(['ok' => false, 'error' => 'Kies een geldig bedrijf.'], 400);
    }

    try {
        providentia_send_json(providentia_plannings_payload($company));
    } catch (Throwable $error) {
        providentia_runtime_error_payload($error, 'Planningsvoorstellen ophalen mislukt.');
    }
}
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="brand.css">
    <title>Providentia</title>
    <style>
        :root {
            --bg: var(--kvt-page-bg);
            --panel: #ffffff;
            --panel-alt: #f7fbff;
            --text: var(--kvt-text);
            --muted: var(--kvt-muted);
            --line: var(--kvt-line);
            --brand: var(--kvt-main-blue);
            --brand-light: var(--kvt-light-blue);
            --brand-dark: var(--kvt-perkins-blue);
            --danger: var(--kvt-danger);
            --shadow: 0 18px 40px rgba(0, 82, 155, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: var(--text);
            background:
                radial-gradient(900px 500px at -10% -10%, rgba(51, 204, 255, 0.18), transparent 55%),
                radial-gradient(900px 500px at 110% 0%, rgba(0, 153, 204, 0.14), transparent 50%),
                radial-gradient(700px 400px at 50% 110%, rgba(0, 82, 155, 0.08), transparent 50%),
                var(--bg);
        }

        .page {
            max-width: 1440px;
            margin: 0 auto;
            padding: 16px;
        }

        .hero {
            position: relative;
            overflow: hidden;
            padding: 18px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(0, 82, 155, 0.98), rgba(0, 153, 204, 0.96));
            color: #fff;
            box-shadow: 0 20px 40px rgba(0, 82, 155, 0.24);
        }

        .hero-grid {
            display: grid;
            gap: 16px;
        }

        .hero-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .hero-logo {
            width: min(240px, 54vw);
            height: auto;
            display: block;
        }

        .hero-kicker {
            margin: 0;
            font-size: 12px;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.78);
        }

        .hero h1 {
            margin: 4px 0 0;
            font-size: clamp(28px, 5vw, 40px);
            line-height: 1.05;
        }

        .shell {
            display: grid;
            gap: 16px;
            margin-top: 16px;
        }

        .panel {
            background: var(--panel);
            border: 1px solid rgba(0, 82, 155, 0.12);
            border-radius: 20px;
            box-shadow: var(--shadow);
            padding: 16px;
        }

        .panel-title {
            margin: 0 0 6px;
            font-size: 18px;
            color: var(--brand-dark);
        }

        .panel-subtitle {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.45;
        }

        .controls-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: 1fr;
            margin-top: 14px;
        }

        .filters-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: 1fr;
            margin-top: 12px;
        }

        .field label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 700;
            color: var(--muted);
        }

        .toggle-field {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 700;
            color: var(--brand-dark);
            user-select: none;
        }

        .toggle-field input {
            width: 18px;
            height: 18px;
            accent-color: var(--brand-dark);
            margin: 0;
        }

        input,
        select,
        button {
            width: 100%;
            min-height: 44px;
            border-radius: 12px;
            border: 1px solid #b8cbe1;
            padding: 10px 12px;
            font-size: 15px;
            font-family: inherit;
        }

        button {
            cursor: pointer;
            font-weight: 700;
        }

        .btn-main {
            background: linear-gradient(135deg, var(--brand-dark), var(--brand));
            color: #fff;
            border-color: transparent;
        }

        button[disabled] {
            opacity: 0.55;
            cursor: default;
        }

        .status-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 16px;
            background: var(--panel-alt);
            border: 1px solid #dbe9f7;
        }

        .status-text {
            margin: 0;
            font-size: 14px;
            color: var(--muted);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 32px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(0, 153, 204, 0.11);
            border: 1px solid rgba(0, 153, 204, 0.2);
            font-size: 13px;
            font-weight: 700;
            color: var(--brand-dark);
        }

        .table-wrap {
            margin-top: 16px;
            overflow: auto;
            border: 1px solid #d9e5f1;
            border-radius: 16px;
            max-height: min(72vh, 900px);
        }

        .data-table {
            width: 100%;
            min-width: 1100px;
            border-collapse: collapse;
            font-size: 13px;
        }

        .data-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #eef6fc;
            border-bottom: 2px solid #c8dced;
            padding: 10px 8px;
            text-align: left;
            white-space: nowrap;
            user-select: none;
        }

        .data-table tbody td {
            padding: 9px 8px;
            border-bottom: 1px solid #e8f0f7;
            vertical-align: top;
            word-break: break-word;
        }

        .data-table tbody tr:nth-child(even) {
            background: #f9fcff;
        }

        .data-table tbody tr:hover {
            background: #f0f8ff;
        }

        .sort-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: auto;
            min-height: 0;
            padding: 0;
            border: 0;
            background: transparent;
            color: inherit;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .sort-indicator {
            font-size: 11px;
            color: var(--muted);
        }

        .warning-cell {
            font-weight: 700;
            color: #9a6700;
        }

        .qty-cell {
            cursor: help;
            text-decoration: underline dotted rgba(0, 82, 155, 0.35);
            text-underline-offset: 3px;
        }

        .qty-tooltip {
            position: fixed;
            z-index: 12000;
            display: none;
            min-width: 220px;
            max-width: min(320px, calc(100vw - 24px));
            padding: 10px 12px;
            border-radius: 12px;
            background: #0f2740;
            color: #fff;
            font-size: 13px;
            line-height: 1.45;
            box-shadow: 0 14px 30px rgba(4, 15, 29, 0.28);
            pointer-events: none;
        }

        .qty-tooltip.is-visible {
            display: block;
        }

        .qty-tooltip strong {
            color: #9ee0ff;
        }

        .empty-state {
            padding: 18px 12px;
            border-radius: 16px;
            border: 1px dashed #b6c9df;
            background: #f9fcff;
            color: var(--muted);
            font-size: 14px;
        }

        .loader-overlay {
            position: fixed;
            top: 14px;
            right: 14px;
            z-index: 11000;
            display: none;
            width: min(320px, calc(100vw - 28px));
            pointer-events: none;
        }

        .loader-overlay.is-visible {
            display: block;
        }

        .loader-card {
            pointer-events: auto;
            border-radius: 18px;
            padding: 16px;
            background: #fff;
            border: 1px solid #d9e5f1;
            box-shadow: 0 18px 40px rgba(10, 18, 29, 0.18);
        }

        .loader-title {
            margin: 0;
            font-size: 18px;
            color: var(--brand-dark);
        }

        .loader-subtitle {
            margin: 6px 0 0;
            font-size: 13px;
            color: var(--muted);
        }

        @media (min-width: 840px) {
            .hero-grid {
                grid-template-columns: minmax(0, 1.5fr) minmax(250px, 0.85fr);
                align-items: end;
            }

            .controls-grid {
                grid-template-columns: minmax(0, 1fr) auto;
            }

            .filters-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 839px) {
            .page {
                padding: 12px;
            }
        }
    </style>
</head>

<body>
    <div class="page">
        <header class="hero">
            <div class="hero-grid">
                <div>
                    <div class="hero-brand">
                        <img class="hero-logo" src="logo-website.png" alt="Providentia logo">
                    </div>
                    <p class="hero-kicker">Providentia</p>
                    <h1>Planningsvoorstellen</h1>
                </div>
                <div>
                    <?php echo $cacheWidget; ?>
                </div>
            </div>
        </header>

        <main class="shell">
            <section class="panel">
                <h2 class="panel-title">Bedrijf en filters</h2>
                <p class="panel-subtitle">Kies een bedrijf om planningsvoorstellen uit Business Central te laden. Je keuze wordt onthouden.</p>

                <div class="controls-grid">
                    <div class="field">
                        <label for="companySelect">Bedrijf</label>
                        <select id="companySelect" autocomplete="off">
                            <?php foreach ($companies as $company): ?>
                                <option value="<?= htmlspecialchars($company, ENT_QUOTES) ?>" <?= $company === $selectedCompany ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($company) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>&nbsp;</label>
                        <button id="loadButton" class="btn-main" type="button">Laad planningsvoorstellen</button>
                    </div>
                </div>

                <div class="filters-grid">
                    <div class="field">
                        <label for="globalSearchInput">Zoeken (alle kolommen)</label>
                        <input id="globalSearchInput" type="search" placeholder="Live zoeken..." autocomplete="off" spellcheck="false">
                    </div>
                    <div class="field">
                        <label for="articleFilterInput">Filter artikelnummer</label>
                        <input id="articleFilterInput" type="search" placeholder="Bijv. 10001" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="field">
                        <label for="vendorFilterInput">Filter leveranciersnummer</label>
                        <input id="vendorFilterInput" type="search" placeholder="Bijv. L00001" autocomplete="off" spellcheck="false">
                    </div>
                </div>

                <div class="field" style="margin-top:10px;">
                    <label class="toggle-field" for="onlyTeBestellenToggle">
                        <input type="checkbox" id="onlyTeBestellenToggle" autocomplete="off" checked>
                        Alleen tonen indien niet genoeg op voorraad
                    </label>
                </div>

                <div class="status-bar" aria-live="polite">
                    <p id="statusText" class="status-text">Kies een bedrijf en laad de planningsvoorstellen.</p>
                    <span id="summaryBadge" class="badge" style="display:none;"></span>
                </div>
            </section>

            <section class="panel">
                <h2 class="panel-title">Planningsvoorstellen</h2>
                <p class="panel-subtitle">Sorteer op een kolomkop. Nodig = Original_Quantity − Quantity − Reserved_Quantity.</p>

                <div id="tableArea">
                    <div class="empty-state">Nog geen gegevens geladen.</div>
                </div>
            </section>
        </main>
    </div>

    <div id="qtyTooltip" class="qty-tooltip" role="tooltip" hidden></div>

    <div id="loaderOverlay" class="loader-overlay" aria-live="polite" aria-busy="true">
        <div class="loader-card">
            <h2 class="loader-title">Laden</h2>
            <p id="loaderSubtitle" class="loader-subtitle">Planningsvoorstellen ophalen...</p>
        </div>
    </div>

    <script>
        (function ()
        {
            const STORAGE_KEY = 'providentia.selected_company';
            const STORAGE_KEY_ONLY_TE_BESTELLEN = 'providentia.only_te_bestellen';

            const columns = [
                { key: 'warning', label: 'Waarschuwing', type: 'text' },
                { key: 'no', label: 'No', type: 'text' },
                { key: 'action_message', label: 'Planningsboodschap', type: 'text' },
                { key: 'due_date', label: 'Vervaldatum', type: 'date' },
                { key: 'starting_time', label: 'Begintijd', type: 'text' },
                { key: 'ending_time', label: 'Eindtijd', type: 'text' },
                { key: 'description', label: 'Description', type: 'text' },
                { key: 'te_bestellen_display', label: 'Nodig', type: 'number', sortKey: 'te_bestellen' },
                { key: 'replenishment_system', label: 'Aanvullingsmethode', type: 'text' },
                { key: 'vendor_no', label: 'Leveranciersnummer', type: 'text' },
            ];

            const companySelect = document.getElementById('companySelect');
            const loadButton = document.getElementById('loadButton');
            const globalSearchInput = document.getElementById('globalSearchInput');
            const articleFilterInput = document.getElementById('articleFilterInput');
            const vendorFilterInput = document.getElementById('vendorFilterInput');
            const onlyTeBestellenToggle = document.getElementById('onlyTeBestellenToggle');
            const statusText = document.getElementById('statusText');
            const summaryBadge = document.getElementById('summaryBadge');
            const tableArea = document.getElementById('tableArea');
            const loaderOverlay = document.getElementById('loaderOverlay');
            const loaderSubtitle = document.getElementById('loaderSubtitle');
            const qtyTooltip = document.getElementById('qtyTooltip');

            const state = {
                allRows: [],
                sortKey: 'due_date',
                sortDir: 'desc',
                globalSearch: '',
                articleFilter: '',
                vendorFilter: '',
                onlyTeBestellen: true,
                loading: false,
            };

            function escapeHtml (value)
            {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function postJson (url, payload)
            {
                return fetch(url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: new URLSearchParams(payload),
                    credentials: 'same-origin',
                    cache: 'no-store',
                }).then(function (response)
                {
                    return response.text().then(function (text)
                    {
                        let data = null;
                        try
                        {
                            data = text.trim() === '' ? null : JSON.parse(text);
                        } catch (error)
                        {
                            throw new Error('Ongeldige JSON-respons van de server.');
                        }

                        if (!response.ok)
                        {
                            throw new Error((data && data.error) ? data.error : ('HTTP ' + response.status));
                        }

                        return data;
                    });
                });
            }

            function formatQuantity (value)
            {
                const number = Number(value);
                if (!Number.isFinite(number))
                {
                    return '0';
                }

                if (Math.abs(number - Math.round(number)) < 0.00001)
                {
                    return String(Math.round(number));
                }

                return number.toFixed(4).replace('.', ',').replace(/,?0+$/, '');
            }

            function quantityWithUnit (value, unit)
            {
                const display = formatQuantity(value);
                const suffix = String(unit || '').trim();
                return suffix === '' ? display : display + ' ' + suffix;
            }

            function buildQtyTooltipHtml (row)
            {
                const unit = row.unit_of_measure_code || '';
                return '<div><strong>Nodig:</strong> ' + escapeHtml(quantityWithUnit(row.original_quantity, unit)) + '</div>'
                    + '<div><strong>In opslag:</strong> ' + escapeHtml(quantityWithUnit(row.quantity, unit)) + '</div>'
                    + '<div><strong>Waarvan gereserveerd:</strong> ' + escapeHtml(quantityWithUnit(row.reserved_quantity, unit)) + '</div>';
            }

            function hideQtyTooltip ()
            {
                qtyTooltip.classList.remove('is-visible');
                qtyTooltip.hidden = true;
                qtyTooltip.innerHTML = '';
            }

            function showQtyTooltip (cell, row)
            {
                qtyTooltip.innerHTML = buildQtyTooltipHtml(row);
                qtyTooltip.hidden = false;
                qtyTooltip.classList.add('is-visible');

                const rect = cell.getBoundingClientRect();
                const tooltipRect = qtyTooltip.getBoundingClientRect();
                let top = rect.bottom + 8;
                let left = rect.left;

                if (top + tooltipRect.height > window.innerHeight - 8)
                {
                    top = rect.top - tooltipRect.height - 8;
                }

                if (left + tooltipRect.width > window.innerWidth - 8)
                {
                    left = window.innerWidth - tooltipRect.width - 8;
                }

                left = Math.max(8, left);
                top = Math.max(8, top);

                qtyTooltip.style.top = top + 'px';
                qtyTooltip.style.left = left + 'px';
            }

            function formatDate (value)
            {
                const text = String(value || '').trim();
                if (text === '' || text.indexOf('0001-01-01') === 0)
                {
                    return '';
                }

                const parts = text.split('T')[0].split('-');
                if (parts.length === 3)
                {
                    return parts[2] + '-' + parts[1] + '-' + parts[0];
                }

                return text;
            }

            function setLoading (isLoading, subtitle)
            {
                state.loading = Boolean(isLoading);
                loadButton.disabled = state.loading;
                companySelect.disabled = state.loading;
                loaderOverlay.classList.toggle('is-visible', state.loading);
                if (subtitle)
                {
                    loaderSubtitle.textContent = subtitle;
                }
            }

            function setStatus (message, isError)
            {
                statusText.textContent = message;
                statusText.style.color = isError ? 'var(--danger)' : 'var(--muted)';
            }

            function setSummary (visible, total)
            {
                if (!Number.isFinite(total) || total <= 0)
                {
                    summaryBadge.style.display = 'none';
                    return;
                }

                summaryBadge.style.display = 'inline-flex';
                if (visible === total)
                {
                    summaryBadge.textContent = total.toLocaleString('nl-NL') + ' regels';
                }
                else
                {
                    summaryBadge.textContent = visible.toLocaleString('nl-NL') + ' van ' + total.toLocaleString('nl-NL') + ' regels';
                }
            }

            function rememberCompany ()
            {
                try
                {
                    localStorage.setItem(STORAGE_KEY, companySelect.value);
                } catch (error)
                {
                    void error;
                }
            }

            function rememberOnlyTeBestellen ()
            {
                try
                {
                    localStorage.setItem(STORAGE_KEY_ONLY_TE_BESTELLEN, state.onlyTeBestellen ? '1' : '0');
                } catch (error)
                {
                    void error;
                }
            }

            function restoreOnlyTeBestellen ()
            {
                let enabled = true;

                try
                {
                    const remembered = localStorage.getItem(STORAGE_KEY_ONLY_TE_BESTELLEN);
                    if (remembered !== null)
                    {
                        enabled = remembered !== '0' && remembered !== 'false';
                    }
                } catch (error)
                {
                    void error;
                }

                state.onlyTeBestellen = enabled;
                if (onlyTeBestellenToggle)
                {
                    onlyTeBestellenToggle.checked = enabled;
                }
            }

            function rowHasTeBestellen (row)
            {
                const value = Number(row.te_bestellen);
                return Number.isFinite(value) && value > 0;
            }

            function restoreCompany ()
            {
                try
                {
                    const remembered = localStorage.getItem(STORAGE_KEY);
                    if (!remembered)
                    {
                        return;
                    }

                    const option = Array.from(companySelect.options).find(function (item)
                    {
                        return item.value === remembered;
                    });

                    if (option)
                    {
                        companySelect.value = remembered;
                    }
                } catch (error)
                {
                    void error;
                }
            }

            function columnByKey (key)
            {
                return columns.find(function (column)
                {
                    return column.key === key || column.sortKey === key;
                }) || null;
            }

            function sortValue (row, key)
            {
                const column = columnByKey(key);
                const valueKey = column && column.sortKey ? column.sortKey : key;
                const raw = row[valueKey];

                if (column && column.type === 'number')
                {
                    const number = Number(raw);
                    return Number.isFinite(number) ? number : 0;
                }

                if (column && column.type === 'date')
                {
                    return String(raw || '');
                }

                return String(raw || '').toLowerCase();
            }

            function compareRows (left, right)
            {
                const leftValue = sortValue(left, state.sortKey);
                const rightValue = sortValue(right, state.sortKey);
                let result = 0;

                if (typeof leftValue === 'number' && typeof rightValue === 'number')
                {
                    result = leftValue - rightValue;
                }
                else
                {
                    result = String(leftValue).localeCompare(String(rightValue), 'nl', { numeric: true, sensitivity: 'base' });
                }

                if (result === 0)
                {
                    result = String(left.no || '').localeCompare(String(right.no || ''), 'nl', { numeric: true, sensitivity: 'base' });
                }

                return state.sortDir === 'desc' ? -result : result;
            }

            function visibleRows ()
            {
                const globalQuery = String(state.globalSearch || '').trim().toLowerCase();
                const articleQuery = String(state.articleFilter || '').trim().toLowerCase();
                const vendorQuery = String(state.vendorFilter || '').trim().toLowerCase();

                return state.allRows.filter(function (row)
                {
                    if (state.onlyTeBestellen && !rowHasTeBestellen(row))
                    {
                        return false;
                    }

                    if (articleQuery !== '' && String(row.no || '').toLowerCase().indexOf(articleQuery) === -1)
                    {
                        return false;
                    }

                    if (vendorQuery !== '' && String(row.vendor_no || '').toLowerCase().indexOf(vendorQuery) === -1)
                    {
                        return false;
                    }

                    if (globalQuery === '')
                    {
                        return true;
                    }

                    const haystack = columns.map(function (column)
                    {
                        const valueKey = column.sortKey || column.key;
                        if (column.type === 'date')
                        {
                            return formatDate(row[column.key]);
                        }
                        return String(row[valueKey] ?? row[column.key] ?? '');
                    }).join(' ').toLowerCase();

                    return haystack.indexOf(globalQuery) !== -1;
                });
            }

            function sortIndicator (key)
            {
                if (state.sortKey !== key)
                {
                    return '↕';
                }

                return state.sortDir === 'asc' ? '↑' : '↓';
            }

            function renderTable ()
            {
                hideQtyTooltip();
                const rows = visibleRows().slice().sort(compareRows);
                setSummary(rows.length, state.allRows.length);

                if (state.allRows.length === 0)
                {
                    tableArea.innerHTML = '<div class="empty-state">Nog geen gegevens geladen.</div>';
                    return;
                }

                if (rows.length === 0)
                {
                    tableArea.innerHTML = '<div class="empty-state">Geen regels gevonden met de huidige filters.</div>';
                    return;
                }

                let html = '<div class="table-wrap"><table class="data-table"><thead><tr>';
                columns.forEach(function (column)
                {
                    const sortKey = column.sortKey || column.key;
                    html += '<th><button type="button" class="sort-button" data-sort-key="' + escapeHtml(sortKey) + '">'
                        + escapeHtml(column.label)
                        + ' <span class="sort-indicator">' + sortIndicator(sortKey) + '</span></button></th>';
                });
                html += '</tr></thead><tbody>';

                rows.forEach(function (row, rowIndex)
                {
                    html += '<tr>';
                    columns.forEach(function (column)
                    {
                        let value = row[column.key];
                        if (column.type === 'date')
                        {
                            value = formatDate(value);
                        }

                        let className = '';
                        if (column.key === 'warning' && String(value || '').trim() !== '')
                        {
                            className = 'warning-cell';
                        }
                        else if (column.key === 'te_bestellen_display')
                        {
                            className = 'qty-cell';
                        }

                        const classAttr = className !== '' ? ' class="' + className + '"' : '';
                        const qtyAttrs = column.key === 'te_bestellen_display'
                            ? ' data-qty-row="' + rowIndex + '"'
                            : '';
                        html += '<td' + classAttr + qtyAttrs + '>' + escapeHtml(value) + '</td>';
                    });
                    html += '</tr>';
                });

                html += '</tbody></table></div>';
                tableArea.innerHTML = html;
                tableArea._visibleRows = rows;
            }

            function statusSuffix ()
            {
                const parts = [];
                if (state.onlyTeBestellen)
                {
                    parts.push('alleen niet genoeg tonen');
                }
                if (state.globalSearch.trim() !== '')
                {
                    parts.push('zoekterm: "' + state.globalSearch.trim() + '"');
                }
                if (state.articleFilter.trim() !== '')
                {
                    parts.push('artikel: "' + state.articleFilter.trim() + '"');
                }
                if (state.vendorFilter.trim() !== '')
                {
                    parts.push('leverancier: "' + state.vendorFilter.trim() + '"');
                }

                return parts.length > 0 ? ' (' + parts.join(', ') + ').' : '.';
            }

            function applyFilters ()
            {
                state.globalSearch = String(globalSearchInput.value || '');
                state.articleFilter = String(articleFilterInput.value || '');
                state.vendorFilter = String(vendorFilterInput.value || '');
                state.onlyTeBestellen = !!(onlyTeBestellenToggle && onlyTeBestellenToggle.checked);
                rememberOnlyTeBestellen();
                renderTable();

                if (state.allRows.length > 0)
                {
                    const visible = visibleRows().length;
                    setStatus(visible + ' zichtbare regels van ' + state.allRows.length + ' totaal' + statusSuffix(), false);
                }
            }

            function toggleSort (key)
            {
                if (state.sortKey === key)
                {
                    state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
                }
                else
                {
                    state.sortKey = key;
                    state.sortDir = 'asc';
                }

                renderTable();
            }

            async function loadRows ()
            {
                const company = String(companySelect.value || '').trim();
                if (company === '')
                {
                    setStatus('Kies eerst een bedrijf.', true);
                    return;
                }

                rememberCompany();

                try
                {
                    setLoading(true, 'Planningsvoorstellen ophalen voor ' + company + '...');
                    const payload = await postJson('index.php?action=planningsvoorstellen', { company: company });
                    state.allRows = Array.isArray(payload.rows) ? payload.rows : [];
                    applyFilters();
                    const visible = visibleRows().length;
                    setStatus(visible + ' zichtbare regels van ' + state.allRows.length + ' geladen voor ' + company + statusSuffix(), false);
                }
                catch (error)
                {
                    state.allRows = [];
                    renderTable();
                    setStatus((error && error.message) ? error.message : 'Laden mislukt.', true);
                }
                finally
                {
                    setLoading(false);
                }
            }

            loadButton.addEventListener('click', function ()
            {
                void loadRows();
            });

            companySelect.addEventListener('change', function ()
            {
                rememberCompany();
                state.allRows = [];
                renderTable();
                setSummary(0, 0);
                setStatus('Bedrijf gewijzigd. Laad opnieuw om planningsvoorstellen te tonen.', false);
            });

            globalSearchInput.addEventListener('input', applyFilters);
            articleFilterInput.addEventListener('input', applyFilters);
            vendorFilterInput.addEventListener('input', applyFilters);
            if (onlyTeBestellenToggle)
            {
                onlyTeBestellenToggle.addEventListener('change', applyFilters);
            }

            tableArea.addEventListener('click', function (event)
            {
                const button = event.target.closest('[data-sort-key]');
                if (!button)
                {
                    return;
                }

                toggleSort(String(button.getAttribute('data-sort-key') || ''));
            });

            tableArea.addEventListener('mouseover', function (event)
            {
                const cell = event.target.closest('td.qty-cell[data-qty-row]');
                if (!cell || !tableArea._visibleRows)
                {
                    return;
                }

                const rowIndex = Number(cell.getAttribute('data-qty-row'));
                const row = tableArea._visibleRows[rowIndex];
                if (!row)
                {
                    return;
                }

                showQtyTooltip(cell, row);
            });

            tableArea.addEventListener('mouseout', function (event)
            {
                const cell = event.target.closest('td.qty-cell[data-qty-row]');
                const related = event.relatedTarget;
                if (!cell)
                {
                    return;
                }

                if (related && (cell === related || cell.contains(related)))
                {
                    return;
                }

                hideQtyTooltip();
            });

            window.addEventListener('scroll', hideQtyTooltip, true);
            window.addEventListener('resize', hideQtyTooltip);

            restoreCompany();
            restoreOnlyTeBestellen();
            renderTable();
        })();
    </script>
</body>

</html>
