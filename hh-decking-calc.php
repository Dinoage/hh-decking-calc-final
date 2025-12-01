<?php
/**
 * Plugin Name:       HH Decking Calculator
 * Description:       Basis calculator/wizard voor Haarlemse Houthandel (Optie 1).
 * Version:           0.1.0
 * Author:            Jij
 * Text Domain:       hh-decking-calc
 * Requires at least: 6.0
 * Requires PHP:      8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HH_DC_VERSION', '0.1.0' );
define( 'HH_DC_PATH', plugin_dir_path( __FILE__ ) );
define( 'HH_DC_URL', plugin_dir_url( __FILE__ ) );

require_once HH_DC_PATH . 'includes/config.php';
require_once HH_DC_PATH . 'includes/class-calculator.php';
require_once HH_DC_PATH . 'includes/class-rest.php';

use HH\DeckingCalc\REST;
use HH\DeckingCalc\Calculator;

/**
 * Assets.
 */
function hh_dc_enqueue_assets() {
	// Alleen waar shortcode staat (basic check).
	if ( ! is_singular() ) {
		return;
	}

	wp_register_style(
		'hh-dc-wizard',
		HH_DC_URL . 'assets/css/wizard.css',
		array(),
		HH_DC_VERSION
	);

	wp_register_script(
		'hh-dc-wizard',
		HH_DC_URL . 'assets/js/wizard.js',
		array( 'wp-i18n' ),
		HH_DC_VERSION,
		true
	);

	wp_localize_script(
		'hh-dc-wizard',
		'HHDC',
		array(
			'rest'   => array(
				'base' => esc_url_raw( get_rest_url( null, 'hh-decking/v1' ) ),
			),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'i18n'   => array(
				'calcError' => __( 'Er ging iets mis bij berekenen.', 'hh-decking-calc' ),
				'cartError' => __( 'Toevoegen aan winkelmand mislukt.', 'hh-decking-calc' ),
			),
			'config' => array(
				'wastePercent' => \HH\DeckingCalc\CONFIG['defaults']['waste_percent'],
				'mappings'     => \HH\DeckingCalc\CONFIG['mappings'], // 🔑 hele mapping naar JS
			),
		)
	);

	wp_enqueue_style( 'hh-dc-wizard' );
	wp_enqueue_script( 'hh-dc-wizard' );
}
add_action( 'wp_enqueue_scripts', 'hh_dc_enqueue_assets' );

/**
 * Shortcode: [hh_decking_calculator]
 */
function hh_dc_shortcode() {
	ob_start();
	?>
	<div class="hh-dc">
		<form id="dc-form" class="hh-dc-form" novalidate>
			
			<!-- Stap 1: type -->
			<div class="hh-dc-step">
				<label for="dc-type"><?php esc_html_e( 'Type materiaal', 'hh-decking-calc' ); ?></label>
				<select id="dc-type" name="type" required>
					<option value=""><?php esc_html_e( 'Kies…', 'hh-decking-calc' ); ?></option>
					<option value="hout">Hout</option>
					<option value="bamboe">Bamboe</option>
					<option value="composiet">Composiet</option>
				</select>
			</div>

			<!-- Stap 1b: subtype -->
			<div id="dc-subtype-row" class="hh-dc-step" style="display:none;">
				<label for="dc-subtype"><?php esc_html_e( 'Subtype', 'hh-decking-calc' ); ?></label>
				<select id="dc-subtype" name="subtype">
					<option value=""><?php esc_html_e( 'Kies soort…', 'hh-decking-calc' ); ?></option>
					<!-- Hout-opties -->
					<option value="bangkirai" data-type="hout">Bangkirai</option>
					<option value="angelim" data-type="hout">Angelim Vermelho</option>
					<option value="douglas" data-type="hout">Douglas</option>

					<!-- Bamboe-opties -->
					<option value="plank" data-type="bamboe">Vlonderplank</option>
					<option value="tegel" data-type="bamboe">Vlondertegel</option>
					<option value="visgraat" data-type="bamboe">Visgraat</option>
				</select>
			</div>

			<!-- Stap 2: afmetingen -->
			<div class="hh-dc-step">
				<label for="dc-length"><?php esc_html_e( 'Lengte terras (m)', 'hh-decking-calc' ); ?></label>
				<input type="number" step="0.01" min="0" id="dc-length" name="length" required>
			</div>
			<div class="hh-dc-step">
				<label for="dc-width"><?php esc_html_e( 'Breedte terras (m)', 'hh-decking-calc' ); ?></label>
				<input type="number" step="0.01" min="0" id="dc-width" name="width" required>
			</div>

			<!-- Stap 2b: plaatsing -->
			<div class="hh-dc-step">
			<label for="dc-poles"><?php esc_html_e('Plaatsing', 'hh-decking-calc'); ?></label>
			<select id="dc-poles" name="poles" required>
				<option value="none"><?php esc_html_e('Zonder piketpalen (balkon)', 'hh-decking-calc'); ?></option>
				<option value="with"><?php esc_html_e('Met piketpalen (tuin)', 'hh-decking-calc'); ?></option>
			</select>
			</div>

			<!-- Alleen tonen bij "met piketpalen" -->
			<div id="dc-pole-size-row" class="hh-dc-step" style="display:none;">
			<label for="dc-pole-size"><?php esc_html_e('Maat piketpaal', 'hh-decking-calc'); ?></label>
			<select id="dc-pole-size" name="pole_size">
				<option value="40x40">40 × 40 mm</option>
				<option value="50x50">50 × 50 mm</option>
			</select>
			</div>


			<!-- Stap 3: hoogte -->
			<div class="hh-dc-step">
				<label for="dc-height"><?php esc_html_e( 'Hoogte plank (mm)', 'hh-decking-calc' ); ?></label>
				<select id="dc-height" name="height" required>
					<option value=""><?php esc_html_e( 'Kies…', 'hh-decking-calc' ); ?></option>
				</select>
			</div>

			<!-- Stap 4: kleur (alleen bamboe/composiet) -->
			<div id="dc-color-row" class="hh-dc-step" style="display:none;">
				<label for="dc-color"><?php esc_html_e( 'Kleur', 'hh-decking-calc' ); ?></label>
				<select id="dc-color" name="color">
					<option value=""><?php esc_html_e( 'Kies kleur…', 'hh-decking-calc' ); ?></option>
					<option value="stone_grey">Stone Grey</option>
					<option value="ipe">Ipe</option>
					<option value="teak">Teak</option>
					<option value="ebony">Ebony</option>
					<option value="espresso">Espresso</option>
				</select>
			</div>

			<!-- Acties -->
			<div class="hh-dc-actions">
				<button type="submit" id="dc-submit" class="button button-primary">
					<?php esc_html_e( 'Bereken', 'hh-decking-calc' ); ?>
				</button>
				<button type="button" id="dc-add-to-cart" class="button" style="display:none;">
					<?php esc_html_e( 'Alles in winkelmand', 'hh-decking-calc' ); ?>
				</button>
			</div>
		</form>

		<div id="dc-result" class="hh-dc-result" aria-live="polite"></div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'hh_decking_calculator', 'hh_dc_shortcode' );

/**
 * REST init.
 */
add_action(
	'rest_api_init',
	static function () {
		REST::register_routes();
	}
);