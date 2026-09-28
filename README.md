# Providentia

OData-leesacties gaan via Mímir wanneer `$mimirApi` in `web/auth.php` staat. Blijft die aanroep uit (verbinding/timeout, geen 2xx, ongeldige JSON of een Mímir-fout), dan haalt Providentia dezelfde gegevens rechtstreeks bij Business Central op via `$baseUrl`, `$auth` / `$auth_list`, `$environment` en de lokale odata-filecache, en slaat Mímir voor de rest van dat PHP-proces over.

Laat die BC-gegevens in `auth.php` naast `$mimirApi` staan. Zonder die gegevens wordt de oorspronkelijke Mímir-fout opnieuw gegooid. Zonder `$mimirApi` blijft alleen de directe BC-route actief. Een voorbeeld staat in `web/auth_TEMPLATE.php`. `auth.php` zelf wordt niet weggeschreven of geback-upt.

`web/index.php` laadt `auth.php` voor elk live verzoek. Er is geen aparte `nightly.php`. CLI- en cron-scripts die `odata.php` includen krijgen dezelfde fallback (lange timeout op de `cli`-SAPI). Ontbreken de BC-variabelen dan nog, dan worden ze alsnog uit `auth.php` gelezen op het moment dat Mímir faalt.
