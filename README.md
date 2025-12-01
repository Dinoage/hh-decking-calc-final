# HH Decking Calculator (Optie 1)

Basis WordPress/WooCommerce plugin met een stappenwizard, rekenlogica in code en REST-endpoints.

## Installatie
1. Plaats de map `hh-decking-calc/` in `wp-content/plugins/`.
2. Activeer via **Plugins** in WP Admin.
3. Maak een pagina en voeg shortcode toe: `[hh_decking_calculator]`.

## Configuratie
- Open `includes/config.php` en vul je **product/variatie IDs** in bij `CONFIG['mappings']`.
- Pas `waste_percent` aan indien nodig.
- Accessoires-regels zijn mock; later vervangen door Excel-naar-PHP.

## Endpoints
- `POST /wp-json/hh-decking/v1/calc` (nonce vereist: `X-WP-Nonce`)
- `POST /wp-json/hh-decking/v1/add-to-cart` (stub, voegt toe aan mand als WC beschikbaar is)

## Security
- REST endpoints vereisen `wp_rest` nonce (voor front-end al meegegeven).
- Input wordt gesanitized en gevalideerd.

## Testen (PHPUnit)
1. Vereist PHP 8.1+ en PHPUnit.
2. Run: `phpunit --bootstrap vendor/autoload.php tests/test-calculator.php` (of direct zonder bootstrap als je global phpunit gebruikt).
3. Test is minimaal en controleert de mock-output.

## Roadmap
- Excel-formules 1-op-1 vertalen naar `Calculator` (pure functions).
- Lengte-strategie helper (dichtstbijzijnde/vast set).
- LinesBuilder centraliseren.
- Order meta uitbreiden.
- Fallbacks voor ontbrekende mappings/voorraad.