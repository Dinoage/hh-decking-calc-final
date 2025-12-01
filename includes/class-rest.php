<?php
namespace HH\DeckingCalc;

use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST {

	public static function register_routes(): void {
		register_rest_route(
			'hh-decking/v1',
			'/calc',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'calc' ),
				'permission_callback' => array( __CLASS__, 'permissions' ),
			)
		);

		register_rest_route(
			'hh-decking/v1',
			'/add-to-cart',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'add_to_cart' ),
				'permission_callback' => array( __CLASS__, 'permissions' ),
			)
		);

		register_rest_route(
			'hh-decking/v1',
			'/variations-map',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'variations_map' ),
				'permission_callback' => static function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'product_id' => array( 'required' => true, 'type' => 'integer' ),
				),
			)
		);
	}

	/**
	 * REST nonce check voor POST.
	 */
	public static function permissions(): bool {
		return (bool) wp_verify_nonce( $_SERVER['HTTP_X_WP_NONCE'] ?? '', 'wp_rest' );
	}

	/**
	 * /calc → berekening.
	 */
	public static function calc( WP_REST_Request $request ): WP_REST_Response {
		// ✅ Lees JSON body correct uit (fetch stuurt application/json)
		$raw = $request->get_body();
		$input = json_decode( $raw, true ) ?? [];

		// Log voor debug
		error_log('HHDC REST /calc input: ' . print_r($input, true));

		if ( empty( $input ) || empty( $input['type'] ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => __( 'Geen geldige invoer ontvangen.', 'hh-decking-calc' ) ),
				400
			);
		}

		// ✅ Stuur volledige input direct door naar Calculator (die zelf sanitizet)
		$result = Calculator::calculate( $input );

		if ( isset( $result['error'] ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => $result['error'] ),
				400
			);
		}

		return new WP_REST_Response(
			array( 'success' => true, 'data' => $result ),
			200
		);
	}

	/**
	 * /add-to-cart → voegt (variaties incl. attributen) toe aan WC cart.
	 */
	public static function add_to_cart( \WP_REST_Request $request ): \WP_REST_Response {
		// ✅ Start WooCommerce context zodat WC()->cart werkt
		if ( function_exists( 'WC' ) ) {
			// Laad sessie
			if ( ! WC()->session ) {
				$session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
				WC()->session = new $session_class();
				WC()->session->init();
			}

			// Laad customer
			if ( ! WC()->customer ) {
				WC()->customer = new \WC_Customer( get_current_user_id(), true );
			}

			// Laad cart
			if ( ! WC()->cart ) {
				WC()->cart = new \WC_Cart();
				WC()->cart->get_cart(); // Forceer laden
			}
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'message' => __( 'WooCommerce winkelmand niet beschikbaar.', 'hh-decking-calc' ) ],
				400
			);
		}

		// ✅ JSON body uitlezen
		$data = json_decode( $request->get_body(), true );
		$lines = $data['lines'] ?? [];

		if ( ! is_array( $lines ) || empty( $lines ) ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'message' => __( 'Geen regels ontvangen.', 'hh-decking-calc' ) ],
				400
			);
		}

		$added = [];

		foreach ( $lines as $line ) {
			$type         = sanitize_key( $line['type'] ?? 'simple' );
			$product_id   = (int) ( $line['product_id'] ?? 0 );
			$variation_id = (int) ( $line['variation_id'] ?? 0 );
			$qty          = max( 1, (int) ( $line['qty'] ?? 0 ) );
			$meta         = is_array( $line['meta'] ?? null ) ? array_map( 'sanitize_text_field', $line['meta'] ) : [];

			if ( $product_id <= 0 ) {
				continue;
			}

			$attributes = [];

			if ( 'variation' === $type && $variation_id > 0 ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation ) {
					foreach ( $variation->get_attributes() as $attr_name => $attr_value ) {
						$attributes[ 'attribute_' . $attr_name ] = $attr_value;
					}
				}
				$cart_key = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $attributes, $meta );
			} else {
				$cart_key = WC()->cart->add_to_cart( $product_id, $qty, 0, [], $meta );
			}

			if ( $cart_key ) {
				$added[] = $cart_key;
			}
		}

		if ( empty( $added ) ) {
			return new \WP_REST_Response(
				[ 'success' => false, 'message' => __( 'Er is niets toegevoegd. Controleer variaties/voorraad.', 'hh-decking-calc' ) ],
				400
			);
		}

		return new \WP_REST_Response(
			[
				'success'  => true,
				'message'  => __( 'Producten toegevoegd aan winkelmand.', 'hh-decking-calc' ),
				'cart_url' => wc_get_cart_url(),
			],
			200
		);
	}


	/**
	 * /variations-map → geef alle variaties en hun attributen terug als JSON.
	 */
	public static function variations_map( WP_REST_Request $request ): WP_REST_Response {
		$product_id = (int) $request->get_param( 'product_id' );
		if ( $product_id <= 0 ) {
			return new WP_REST_Response( array( 'error' => 'product_id ontbreekt' ), 400 );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return new WP_REST_Response( array( 'error' => 'Geen variabel product of niet gevonden' ), 400 );
		}

		$data = array(
			'product_id' => $product_id,
			'attrs'      => $product->get_variation_attributes(),
			'variations' => array(),
		);

		foreach ( $product->get_children() as $vid ) {
			$var = wc_get_product( $vid );
			if ( ! $var ) {
				continue;
			}
			$data['variations'][] = array(
				'variation_id' => $vid,
				'attributes'   => $var->get_attributes(),
				'sku'          => $var->get_sku(),
				'price'        => $var->get_price(),
				'stock'        => $var->get_stock_quantity(),
			);
		}

		return new WP_REST_Response( $data, 200 );
	}
}
