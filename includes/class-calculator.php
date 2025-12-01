<?php
namespace HH\DeckingCalc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Calculator {

	public static function calculate( array $input ): array {
		$type     = sanitize_key( $input['type'] ?? '' );
		$color    = sanitize_key( $input['color'] ?? '' );
		$height   = (int) ( $input['height'] ?? 0 ); // mm
		$len_m    = (float) ( $input['length'] ?? 0 );
		$wid_m    = (float) ( $input['width'] ?? 0 );
		$subtype  = sanitize_key( $input['subtype'] ?? '' );
		$poles    = sanitize_key( $input['poles'] ?? 'none' );
		$poleSize = sanitize_text_field( $input['pole_size'] ?? '' );

		error_log("HHDC DEBUG: input type=$type, subtype=$subtype, height=$height, color=$color, poles=$poles, poleSize=$poleSize");

		if ( $len_m <= 0 || $wid_m <= 0 || empty( $type ) ) {
			return [ 'error' => __( 'Ongeldige invoer. Vul lengte en breedte in.', 'hh-decking-calc' ) ];
		}

		// Bereken totale oppervlakte (zonder waste)
		$surface_m2 = round( $len_m * $wid_m, 2 );

		// Zoek juiste mapping
		$map = self::find_mapping( $type, $height, $color, $subtype );
		if ( ! $map ) {
			return [ 'error' => __( 'Deze combinatie is nog niet gekoppeld. Neem contact op.', 'hh-decking-calc' ) ];
		}

		// Veiligheidscheck
		if ( empty( $map['width_mm'] ) || empty( $map['thick_mm'] ) ) {
			return [ 'error' => __( 'Dit type product (paneel) wordt nog niet ondersteund in de calculator.', 'hh-decking-calc' ) ];
		}

		$lines = [];
		$total_planks_qty = 0;
		$total_rows = 0;

		// CASE A: Hout — (Versie 2.0: Best fit OR 70/30)
		if ( $type === 'hout' ) {
			$dist = self::calculate_hout_planken( $map, $len_m, $wid_m );
			if ( empty( $dist['by_length'] ) || $dist['total_qty'] <= 0 ) {
				return [ 'error' => __( 'Kon aantal planken niet berekenen voor hout.', 'hh-decking-calc' ) ];
			}

			$total_planks_qty = $dist['total_qty'];
			$total_rows       = $dist['rows'];

			foreach ( $dist['by_length'] as $len_mm => $qty ) {
				$variation_id = $map['length_variations'][ $len_mm ] ?? 0;
				if ( $variation_id <= 0 || $qty <= 0 ) { continue; }

				$lines[] = [
					'type'         => 'variation',
					'product_id'   => $map['product'],
					'variation_id' => $variation_id,
					'qty'          => (int) $qty,
					'meta'         => [
						'_hh_dc_summary' => sprintf(
							__( '%s — %d× %d mm (rijen: %d, uitleg: %s)', 'hh-decking-calc' ),
							$map['label'],
							(int) $qty,
							(int) $len_mm,
							(int) $dist['rows'],
							$dist['explain']
						),
					],
				];
			}
		}


		/**
		 * CASE B: Bamboe
		 */
		elseif ( $type === 'bamboe' ) {
			$calc = self::calculate_bamboe_planken( $map, $len_m, $wid_m );
			if ( $calc['qty'] <= 0 ) {
				return [ 'error' => __( 'Kon aantal bamboe planken niet berekenen.', 'hh-decking-calc' ) ];
			}

			$total_planks_qty = $calc['qty'];
			$total_rows       = $calc['rows'];

			$lines[] = [
				'type'       => 'simple',
				'product_id' => $map['product'],
				'qty'        => (int) $calc['qty'],
				'meta'       => [
					'_hh_dc_summary' => sprintf(
						__( '%s — %d× %d mm (rijen: %d, %s)', 'hh-decking-calc' ),
						$map['label'],
						(int) $calc['qty'],
						(int) $calc['board_len_mm'],
						(int) $calc['rows'],
						$calc['explain']
					),
				],
			];
		}

		/**
		 * CASE C: Composiet — (Versie 2.0: Best fit OR 70/30)
		 */
		elseif ( $type === 'composiet' ) {
			$dist = self::calculate_composiet_planken( $map, $len_m, $wid_m );
			if ( empty( $dist['by_length'] ) || $dist['total_qty'] <= 0 ) {
				return [ 'error' => __( 'Kon aantal planken niet berekenen voor composiet.', 'hh-decking-calc' ) ];
			}

			$total_planks_qty = $dist['total_qty'];
			$total_rows       = $dist['rows'];

			foreach ( $dist['by_length'] as $len_mm => $qty ) {
				$variation_id = $map['length_variations'][ $len_mm ] ?? 0;
				if ( $variation_id <= 0 || $qty <= 0 ) { continue; }

				$lines[] = [
					'type'         => 'variation',
					'product_id'   => $map['product'],
					'variation_id' => $variation_id,
					'qty'          => (int) $qty,
					'meta'         => [
						'_hh_dc_summary' => sprintf(
							__( '%s — %d× %d mm (rijen: %d, %s)', 'hh-decking-calc' ),
							$map['label'],
							(int) $qty,
							(int) $len_mm,
							(int) $dist['rows'],
							$dist['explain']
						),
					],
				];
			}
		}

		// === Accessoires stap 1: REGELS ===
		$poles = sanitize_key( $input['poles'] ?? 'none' ); // "with" of "none"
		$regels = self::calc_regels( $len_m, $type, $poles );

		if ( $regels ) {
			$lines[] = [
				'type'       => 'simple',
				'product_id' => $regels['product_id'],
				'qty'        => $regels['qty'],
				'meta'       => [ '_hh_dc_summary' => $regels['_hh_dc_summary'] ],
			];
		}

		// === Accessoires stap 2: PIKETPALEN ===
		$palen = null;
		if ( $poles === 'with' && ! empty( $regels ) ) {
			$palen = self::calc_piketpalen( $regels['qty'], $wid_m, $poleSize );

			if ( $palen ) {
				$lines[] = [
					'type'       => 'simple',
					'product_id' => $palen['product_id'],
					'qty'        => $palen['qty'],
					'meta'       => [ '_hh_dc_summary' => $palen['_hh_dc_summary'] ],
				];
			}
		}

		// === Accessoires stap 3: SCHROEVEN (Hout) ===
		// Nieuwe logica: rijen x regels + koppelingen
		if ( $type === 'hout' && ! empty( $regels ) && $total_planks_qty > 0 ) {
			$schroeven = self::calc_schroeven( $height, $total_planks_qty, $regels['qty'], $total_rows );

			if ( $schroeven ) {
				$lines[] = [
					'type'       => 'variation',
					'product_id' => $schroeven['product_id'],
					'variation_id' => $schroeven['variation_id'],
					'qty'        => $schroeven['qty'],
					'meta'       => [ '_hh_dc_summary' => $schroeven['_hh_dc_summary'] ],
				];
			}
		}

		// === Accessoires stap 4: SLOTBOUTEN ===
		// Nieuwe logica: dikte paal + dikte regel + 15mm
		if ( $poles === 'with' && ! empty( $palen ) ) {
			$slotbouten = self::calc_slotbouten( $palen['qty'], $regels['label'] ?? '', $poleSize );

			if ( $slotbouten ) {
				$lines[] = [
					'type'       => 'simple',
					'product_id' => $slotbouten['product_id'],
					'qty'        => $slotbouten['qty'],
					'meta'       => [ '_hh_dc_summary' => $slotbouten['_hh_dc_summary'] ],
				];
			}
		}
		
		// === Accessoires stap 5: CLIPS (Bamboe & Composiet) ===
		// Visgraat = planken x 4. Anders basis clips (rijen x regels).
		if ( in_array( $type, ['bamboe', 'composiet'], true ) && ! empty( $regels ) && $total_planks_qty > 0 ) {
			$clips = self::calc_clips( $total_planks_qty, $regels['qty'], $total_rows, $subtype );

			foreach ( $clips as $clip ) {
				$lines[] = [
					'type'       => 'simple',
					'product_id' => $clip['product_id'],
					'qty'        => $clip['qty'],
					'meta'       => [ '_hh_dc_summary' => $clip['_hh_dc_summary'] ],
				];
			}
		}

		// ✅ Sluit af met geldige return
		return [
			'surface_m2' => $surface_m2,
			'lines'      => $lines ?: [],
		];
	}

	
	/**
	 * Hout: verdeel aantallen per lengte-variatie.
	 * * UPDATE 2.0:
	 * 1. (NIEUW) Past het in 1 planklengte? -> Pak dichtstbijzijnde plank (geen 70/30).
	 * 2. Langer dan 2x max? -> 1x max + rest berekening.
	 * 3. Anders -> Standaard 70/30 regel.
	 */
	private static function calculate_hout_planken( array $map, float $len_m, float $wid_m ): array {
		$width_mm = (int) ( $map['width_mm'] ?? 0 );
		$lengths  = array_keys( $map['length_variations'] ?? [] );
		
		if ( empty( $width_mm ) || empty( $lengths ) ) {
			return [ 'by_length' => [], 'total_qty' => 0, 'rows' => 0, 'explain' => __( 'Ongeldige plankconfiguratie.', 'hh-decking-calc' ) ];
		}

		sort( $lengths );               // oplopend
		$longest_mm = end( $lengths );  // langste plank
		$longest_m  = $longest_mm / 1000;
		$target_mm  = (int) round($len_m * 1000); 

		// --- Rijen berekenen
		$spacing_mm  = 5;
		$plank_mod_m = ( $width_mm + $spacing_mm ) / 1000;
		$rows        = (int) ceil( $wid_m / $plank_mod_m );

		$by_length = [];
		$explain   = [];

		$add = function( int $len_mm, int $qty ) use ( &$by_length ) {
			if ( $qty <= 0 ) return;
			$by_length[ $len_mm ] = ( $by_length[ $len_mm ] ?? 0 ) + $qty;
		};

		// ---------------------------------------------------------
		// SCENARIO 0 (NIEUW): Past in één lengte?
		// ---------------------------------------------------------
		if ( $len_m <= $longest_m ) {
			// Zoek de kleinste plank die groot genoeg is
			$best_fit_mm = self::ceil_length( $len_m, $lengths );
			
			$add( $best_fit_mm, $rows );
			$explain[] = sprintf('Kort terras (≤ %.2fm): Alles uit 1 lengte (%dmm)', $longest_m, $best_fit_mm);
			
			return [
				'by_length' => $by_length,
				'total_qty' => (int) array_sum( $by_length ),
				'rows'      => $rows,
				'explain'   => implode(' | ', $explain),
			];
		}

		// ---------------------------------------------------------
		// SCENARIO 1: Zeer lang terras (> 2x langste plank)
		// ---------------------------------------------------------
		if ( $len_m > ( 2 * $longest_m ) ) {
			$add( $longest_mm, $rows );
			$rest_m = $len_m - $longest_m;
			$explain[] = sprintf('Lengte > 2x max: 1x %dmm per rij, rest %.2fm', $longest_mm, $rest_m);
			
			// De rest wordt de nieuwe 'target'
			$len_m = $rest_m; 
			$target_mm = (int) round($len_m * 1000);
		}

		// ---------------------------------------------------------
		// SCENARIO 2: 70/30 (of Max-Strat als 70% niet past)
		// ---------------------------------------------------------
		
		$ideal_70_mm = $target_mm * 0.7;

		// Als 70% groter is dan wat we hebben: Maximaliseer plank 1
		if ( $ideal_70_mm > $longest_mm ) {
			
			$add( $longest_mm, $rows );
			$remainder_mm = $target_mm - $longest_mm;
			
			if ( $remainder_mm > 0 ) {
				// Probeer paren voor reststuk
				$pair_target_m = ($remainder_mm * 2) / 1000;
				$L_pair_mm = Calculator::ceil_length( $pair_target_m, $lengths, false );

				if ( $L_pair_mm > 0 ) {
					$qty_pairs = (int) ceil( $rows / 2 );
					$add( $L_pair_mm, $qty_pairs );
					$explain[] = sprintf('Max-Strat: 1x %dmm + Rest (paren in %dmm)', $longest_mm, $L_pair_mm);
				} else {
					$L_single_mm = Calculator::ceil_length( $remainder_mm / 1000, $lengths );
					if ( $L_single_mm == 0 ) $L_single_mm = $longest_mm; // fallback

					$add( $L_single_mm, $rows );
					$explain[] = sprintf('Max-Strat: 1x %dmm + Rest (enkel %dmm)', $longest_mm, $L_single_mm);
				}
			}

		} else {
			// Standaard 70/30
			$seg70 = $len_m * 0.7;
			$seg30 = $len_m * 0.3;

			$L70_mm = Calculator::ceil_length( $seg70, $lengths );
			$add( $L70_mm, $rows );

			$L30_pair_mm = Calculator::ceil_length( 2 * $seg30, $lengths, false );
			
			if ( $L30_pair_mm > 0 ) {
				$qty_pairs = (int) ceil( $rows / 2 );
				$add( $L30_pair_mm, $qty_pairs );
				$explain[] = sprintf('70/30: 70%%(%dmm) + 2x30%% (paren %dmm)', $L70_mm, $L30_pair_mm);
			} else {
				$L30_direct_mm = Calculator::ceil_length( $seg30, $lengths );
				$add( $L30_direct_mm, $rows );
				$explain[] = sprintf('70/30: 70%%(%dmm) + 30%%(%dmm)', $L70_mm, $L30_direct_mm);
			}
		}

		ksort( $by_length );
		return [
			'by_length' => $by_length,
			'total_qty' => (int) array_sum( $by_length ),
			'rows'      => $rows,
			'explain'   => implode(' | ', $explain),
		];
	}



	/**
	 * Kies de kleinste stocklengte (mm) die >= target_m is.
	 * - Als $allow_fallback=true en niets past → retourneer langste.
	 * - Als $allow_fallback=false en niets past → retourneer 0.
	 */
	private static function ceil_length( float $target_m, array $lengths_mm, bool $allow_fallback = true ): int {
		$target_mm = (int) round( $target_m * 1000 );
		$ok = [];
		foreach ( $lengths_mm as $L ) {
			if ( $L >= $target_mm ) { $ok[] = $L; }
		}
		if ( ! empty( $ok ) ) {
			return min( $ok );
		}
		return $allow_fallback ? max( $lengths_mm ) : 0;
	}

	/**
	 * Bamboe-planken (1 lengte): rijen op (breedte / (breedte+6mm)),
	 * daarna totale lopende meters / planklengte, +3% zaagverlies → ceil.
	 */
	private static function calculate_bamboe_planken( array $map, float $len_m, float $wid_m ): array {
		$width_mm = (int) ( $map['width_mm'] ?? 0 );
		if ( $width_mm <= 0 ) {
			return ['qty' => 0, 'rows' => 0, 'board_len_mm' => 0, 'explain' => __( 'Ongeldige bamboe-configuratie.', 'hh-decking-calc' ) ];
		}

		$spacing_mm      = 6;
		$row_width_m     = ( $width_mm + $spacing_mm ) / 1000;                   // m
		$rows            = (int) ceil( $wid_m / $row_width_m );                   // naar boven
		$board_len_mm    = (int) ( $map['product_length_mm'] ?? 1860 );           // mm
		$board_len_m     = $board_len_mm / 1000;                                  // m

		// Volgens formule: (oppervlakte / rijbreedte) = totale lopende meters
		$total_run_m     = ( $len_m * $wid_m ) / $row_width_m;                    // m
		$boards_float    = $total_run_m / $board_len_m;                           // stuks
		$boards_with_waste = ceil( $boards_float * 1.03 );                        // +3% zaagverlies → ceil

		$explain = sprintf(
			'rijbreedte: %.3fm, rijen: %d, totale lm: %.2fm, plank: %.2fm, +3%% zaagverlies',
			$row_width_m,
			$rows,
			$total_run_m,
			$board_len_m
		);

		return [
			'qty'          => (int) $boards_with_waste,
			'rows'         => $rows,
			'board_len_mm' => $board_len_mm,
			'explain'      => $explain,
		];
	}

	/**
	 * Composiet-planken:
	 * UPDATE 2.0:
	 * - Als lengte <= max planklengte: gebruik 1 plank (dichtstbijzijnde).
	 * - Anders (> max): 70/30 verdeling (standaard: 70% langste, 30% kortste).
	 */
	private static function calculate_composiet_planken( array $map, float $len_m, float $wid_m ): array {
		$width_mm = (int) ( $map['width_mm'] ?? 0 );
		$lengths  = array_keys( $map['length_variations'] ?? [] );
		sort( $lengths );

		if ( $width_mm <= 0 || empty( $lengths ) ) {
			return [ 'by_length' => [], 'total_qty' => 0, 'rows' => 0, 'explain' => __( 'Ongeldige composietconfiguratie.', 'hh-decking-calc' ) ];
		}

		$spacing_mm  = 5;
		$row_width_m = ( $width_mm + $spacing_mm ) / 1000;
		$rows        = (int) ceil( $wid_m / $row_width_m );

		$longest_mm = end( $lengths );
		$longest_m  = $longest_mm / 1000;
		
		$by_length = [];
		$explain   = '';

		// SCENARIO 1: Past in één lengte
		if ( $len_m <= $longest_m ) {
			// Zoek de juiste maat (bijv 2900 of 4000)
			$best_fit_mm = self::ceil_length( $len_m, $lengths );
			
			$by_length[$best_fit_mm] = $rows;
			$explain = sprintf('Lengte %.2fm ≤ %.2fm → alleen %dmm × %d', $len_m, $longest_m, $best_fit_mm, $rows);
		}
		
		// SCENARIO 2: Groter dan max lengte → 70/30 verdeling
		else {
			// Aanname bij composiet: we hebben vaak maar 2 maten (kort en lang).
			// We gebruiken de langste voor 70% en de kortste voor 30% (paren).
			$shortest_mm = reset( $lengths ); // kortste plank (bijv 2900)
			
			$seg70_m = $len_m * 0.7;
			$seg30_m = $len_m * 0.3;
			$explain_parts = [];

			// 70% → Langste plank
			$by_length[$longest_mm] = $rows;
			$explain_parts[] = sprintf('70%%: %.2fm → %dmm × %d', $seg70_m, $longest_mm, $rows);

			// 30% → Kortste plank (als paren)
			// (Composiet is duur en strak, dus paren is voorkeur als het past)
			$qty_pairs = (int) ceil( $rows / 2 ); 
			$by_length[$shortest_mm] = $qty_pairs;
			$explain_parts[] = sprintf('30%%: %.2fm → %dmm × %d (paren)', $seg30_m, $shortest_mm, $qty_pairs);

			$explain = implode(' | ', $explain_parts);
		}

		$total_qty = array_sum( $by_length );

		return [
			'by_length' => $by_length,
			'total_qty' => (int) $total_qty,
			'rows'      => $rows,
			'explain'   => $explain,
		];
	}

	/**
	 * Bereken aantal regels (onderconstructie)
	 * - Formule: ceil(lengte / 0.5) + 1
	 * - Kiest juiste product-ID obv materiaal en "poles" (met of zonder piketpalen)
	 */
	private static function calc_regels( float $len_m, string $type, string $poles ): ?array {
		if ( $len_m <= 0 ) {
			return null;
		}

		$regels_qty = (int) ceil( $len_m / 0.5 ) + 1; // elke 50 cm + 1 extra

		$cfg = CONFIG['accessories']['regels'] ?? null;
		if ( ! $cfg || empty( $cfg['product_ids'] ) ) {
			return null;
		}

		// poles = "with" → tuin (altijd hardhoutregels)
		// poles = "none" → balkon → zelfde materiaal als planken
		if ( $poles === 'with' ) {
			// Hardhout-regels (tweede ID)
			$product_id = $cfg['product_ids'][1] ?? reset( $cfg['product_ids'] );
			$label_ctx  = 'Hardhout (tuin)';
		} else {
			switch ( $type ) {
				case 'hout':
					$product_id = $cfg['product_ids'][1] ?? reset( $cfg['product_ids'] ); // hardhoutregel
					$label_ctx  = 'Hardhout (balkon)';
					break;
				case 'bamboe':
				case 'composiet':
					$product_id = $cfg['product_ids'][0] ?? reset( $cfg['product_ids'] ); // douglas/composietregel
					$label_ctx  = ucfirst( $type ) . ' (balkon)';
					break;
				default:
					$product_id = reset( $cfg['product_ids'] );
					$label_ctx  = ucfirst( $type );
			}
		}

		return [
			'qty'        => $regels_qty,
			'product_id' => (int) $product_id,
			'label'      => $cfg['label'] ?? 'Regels',
			'_hh_dc_summary' => sprintf( 'Regels: %d stuks — %s', $regels_qty, $label_ctx ),
		];
	}

	/**
	 * Bereken aantal piketpalen (alleen bij 'with' / tuin)
	 * Formule: regels × (ceil(breedte / 1) + 1)
	 */
	private static function calc_piketpalen( int $regels_qty, float $wid_m, string $pole_size ): ?array {
		if ( $regels_qty <= 0 || $wid_m <= 0 ) {
			return null;
		}

		$cfg = CONFIG['accessories']['piketpalen'] ?? null;
		if ( ! $cfg || empty( $cfg['product_ids'] ) ) {
			return null;
		}

		// Basisformule
		$palen_qty = $regels_qty * ( (int) ceil( $wid_m / 1.0 ) + 1 );

		// Product-ID kiezen op basis van paalmaat (40x40 of 50x50)
		$product_id = match ( $pole_size ) {
			'50x50' => $cfg['product_ids'][1] ?? reset( $cfg['product_ids'] ),
			default => $cfg['product_ids'][0] ?? reset( $cfg['product_ids'] ),
		};

		return [
			'qty'        => $palen_qty,
			'product_id' => (int) $product_id,
			'label'      => $cfg['label'] ?? 'Piketpalen',
			'_hh_dc_summary' => sprintf( 'Piketpalen: %d stuks — %s mm', $palen_qty, $pole_size ),
		];
	}

	/**
	 * Bereken schroeven (alleen bij hout)
	 * NIEUW: (Rijen x Regels x 2) + (Koppelingen x 2)
	 * Koppelingen = Totaal aantal planken - Aantal rijen (ervan uitgaande dat >1 plank per rij een koppeling is).
	 */
	private static function calc_schroeven( int $height_mm, int $plank_qty, int $regels_qty, int $rows ): ?array {
		if ( $plank_qty <= 0 || $regels_qty <= 0 ) {
			return null;
		}

		$cfg = CONFIG['accessories']['hhline_rvs_hardhout_vlonderschroef'] ?? null;
		if ( ! $cfg || empty( $cfg['variations'] ) ) {
			return null;
		}

		// Kies variatie op basis van dikte
		$variation_key = match ( $height_mm ) {
			21 => '5.5x40',
			25 => '5.5x50',
			default => '5.5x60',
		};

		$variation_id = $cfg['variations'][ $variation_key ] ?? 0;
		if ( $variation_id <= 0 ) {
			return null;
		}

		// Berekening:
		// 1. Basis kruisingen: aantal rijen (breedte) x aantal regels (onder) x 2 schroeven
		$base_screws = ( $rows * $regels_qty ) * 2;

		// 2. Koppelingen: Als er meer planken zijn dan rijen, zijn er koppelingen.
		// Elk 'extra' plankdeel betekent 1 koppeling.
		// Bij een koppeling (stuiknaad) komen 2 extra schroeven (dus 4 op die regel ipv 2).
		// We tellen er dus 2 bij op per koppeling.
		$joints = max( 0, $plank_qty - $rows );
		$joint_screws = $joints * 2;

		$total_screws = $base_screws + $joint_screws;
		
		// 200 per doos
		$dozen = (int) ceil( $total_screws / 200 );

		return [
			'qty'           => $dozen,
			'product_id'    => (int) $cfg['product_id'],
			'variation_id'  => (int) $variation_id,
			'label'         => $cfg['label'] ?? 'Schroeven',
			'_hh_dc_summary'=> sprintf(
				'%s — %d doos/dozen (%s, totaal %d schroeven: %d basis + %d koppeling)',
				$cfg['label'] ?? 'Schroeven',
				$dozen,
				$variation_key,
				$total_screws,
				$base_screws,
				$joint_screws
			),
		];
	}

	/**
	 * Bereken slotbouten (alleen bij tuin/piketpalen)
	 * NIEUW: Paaldikte + Regeldikte + 15mm -> Dichtstbijzijnde maat.
	 */
	private static function calc_slotbouten( int $palen_qty, string $regel_label, string $pole_size ): ?array {
		if ( $palen_qty <= 0 ) {
			return null;
		}

		$cfg = CONFIG['accessories']['slotbouten'] ?? null;
		if ( ! $cfg ) {
			return null;
		}

		// Bepaal afmetingen uit strings (grove aanname op basis van productnamen)
		// Piketpaal "40x40" -> 40, "50x50" -> 50
		$paal_mm = (strpos($pole_size, '50') !== false) ? 50 : 40;

		// Regel "40x60" -> 40, "44x70" -> 44 (We zoeken de dikte die gebout wordt)
		// We checken op '44' in de naam, anders 40 (fallback).
		$regel_mm = (strpos($regel_label, '44') !== false) ? 44 : 40;

		// Formule: Paal + Regel + 15mm
		$benodigde_lengte_mm = $paal_mm + $regel_mm + 15;

		// Afronden naar dichtstbijzijnde maat (bijv 10-tallen: 95->100, 109->110)
		// ceil(95/10)*10 = 100.
		$lengte_mm = (int) (ceil($benodigde_lengte_mm / 10) * 10);
		
		// 25 per doos
		$dozen = (int) ceil( $palen_qty / 25 );

		return [
			'qty'        => $dozen,
			'product_id' => (int) ($cfg['product_id'] ?? 0),
			'label'      => $cfg['label'] ?? 'Slotbouten',
			'_hh_dc_summary' => sprintf(
				'%s — %d doos/dozen (%dmm [paal %d+regel %d+15], %d palen)',
				$cfg['label'] ?? 'Slotbouten',
				$dozen,
				$lengte_mm,
				$paal_mm,
				$regel_mm,
				$palen_qty
			),
		];
	}

	/**
	 * Bereken clips (bamboe & composiet)
	 * NIEUW: 
	 * - Visgraat: (Planken * 4) / 100
	 * - Anders: Rijen * Regels (Basis clips) / 100 (of verpakkingsgrootte)
	 */
	private static function calc_clips( int $plank_qty, int $regels_qty, int $rows, string $subtype ): array {
		$out = [];
		
		// Verpakkingsgrootte (standaard 100 in je voorbeeld, pas aan in config indien nodig)
		$pack_size = 100; 

		$cfg_middle = CONFIG['accessories']['tussenclips'] ?? null;
		$cfg_start  = CONFIG['accessories']['startclips'] ?? null; // Startclips vaak wel nodig
		
		// 1. Tussenclips / Montageclips
		if ( $cfg_middle && ! empty( $cfg_middle['product_id'] ) ) {
			
			if ( $subtype === 'visgraat' ) {
				// Formule Visgraat: (aantal planken x 4) / 100
				$total_clips = $plank_qty * 4;
				$dozen = (int) ceil( $total_clips / $pack_size );
				$summary = sprintf('Visgraat clips: %d planken x 4 = %d stuks', $plank_qty, $total_clips);
			} else {
				// Formule Standaard (Bamboe/Composiet): "Zelfde als schroeven" -> 1 clip per kruising
				// (Rijen x Regels)
				$total_clips = $rows * $regels_qty;
				$dozen = (int) ceil( $total_clips / $pack_size );
				$summary = sprintf('Clips: %d rijen x %d regels = %d stuks', $rows, $regels_qty, $total_clips);
			}

			$out[] = [
				'product_id' => (int) $cfg_middle['product_id'],
				'qty'        => $dozen,
				'_hh_dc_summary' => sprintf(
					'%s — %d doos/dozen (%s)',
					$cfg_middle['label'] ?? 'Tussenclips',
					$dozen,
					$summary
				),
			];
		}

		// 2. Startclips (Optioneel, vaak 1 per rij)
		// Als je dit ook wilt schalen zoals schroeven, laat het weten. 
		// Voor nu behouden we de "1 per regel" logica uit de oude set of zetten we het uit als het in "Clips" totaal zit.
		// In je beschrijving zeg je "Zelfde als schroeven alleen andere aantallen". 
		// Vaak zijn startclips apart. Ik laat ze staan zoals ze waren (1 per regel) tenzij je ze weg wilt.
		if ( $cfg_start && ! empty( $cfg_start['product_id'] ) && $subtype !== 'visgraat' ) {
			// Startclips zijn meestal niet per doos van 100 maar per stuk of klein zakje? 
			// Ik laat de oude logica even staan (aantal = aantal regels).
			$out[] = [
				'product_id' => (int) $cfg_start['product_id'],
				'qty'        => $regels_qty,
				'_hh_dc_summary' => sprintf(
					'%s — %d stuks (1 per regel)',
					$cfg_start['label'] ?? 'Startclips',
					$regels_qty
				),
			];
		}

		return $out;
	}


	/**
	 * Vind de juiste mapping uit CONFIG
	 */
	private static function find_mapping( string $type, int $height, string $color = '', string $subtype = '' ): ?array {
		foreach ( CONFIG['mappings'] as $map ) {
			if ( $map['type'] !== $type ) continue;
			if ( $subtype && isset( $map['subtype'] ) && $map['subtype'] !== $subtype ) continue;
			if ( isset( $map['thick_mm'] ) && (int) $map['thick_mm'] !== (int) $height ) continue;
			if ( isset( $map['color'] ) && $map['color'] && $map['color'] !== $color ) continue;
			return $map;
		}
		return null;
	}
}