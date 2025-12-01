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

		// Veiligheidscheck (behalve voor tegels, die hebben soms thick_mm=0 in config)
		if ( $subtype !== 'tegel' && ( empty( $map['width_mm'] ) || empty( $map['thick_mm'] ) ) ) {
			return [ 'error' => __( 'Dit type product (paneel) wordt nog niet ondersteund in de calculator.', 'hh-decking-calc' ) ];
		}

		$lines = [];
		$total_planks_qty = 0;
		$total_rows = 0;

		// =========================================================
		// CASE A: Tegels
		// =========================================================
		if ( $subtype === 'tegel' ) {
			// Formule: Totale m2 / 0.54 -> afronden naar boven = aantal pakken
			$m2_per_pack = 0.54;
			$tiles_per_pack = 6; 

			$packs_needed = (int) ceil( $surface_m2 / $m2_per_pack );
			
			// Totaal aantal losse tegels voor info
			$total_tiles = $packs_needed * $tiles_per_pack;

			if ( $packs_needed <= 0 ) {
				return [ 'error' => __( 'Oppervlakte te klein voor tegels.', 'hh-decking-calc' ) ];
			}

			$lines[] = [
				'type'       => 'simple',
				'product_id' => $map['product'],
				'qty'        => $packs_needed,
				'meta'       => [
					'_hh_dc_summary' => sprintf(
						__( '%s — %d pakken (totaal %d tegels, 6 st/pak, 0.54m²/pak)', 'hh-decking-calc' ),
						$map['label'],
						$packs_needed,
						$total_tiles
					),
				],
			];

			// Tegels hebben meestal geen regels/schroeven nodig in deze calculator context (balkon)
			return [
				'surface_m2' => $surface_m2,
				'lines'      => $lines,
			];
		}

		// =========================================================
		// CASE B: Hout — (Versie 2.0: Best fit OR 70/30)
		// =========================================================
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


		// =========================================================
		// CASE C: Bamboe (Planken & Visgraat)
		// =========================================================
		elseif ( $type === 'bamboe' ) {

			// --- SUB-CASE: Visgraat ---
			if ( $subtype === 'visgraat' ) {
				// < 15 m2 = 5%, >= 15 m2 = 3%
				$waste_multiplier = ( $surface_m2 < 15 ) ? 1.05 : 1.03;

				$b_width_mm = (int) ( $map['width_mm'] ?? 140 );
				$b_length_mm = (int) ( $map['product_length_mm'] ?? 700 ); 

				$b_width_m  = $b_width_mm / 1000;
				$b_length_m = $b_length_mm / 1000;
				
				$plank_m2 = $b_width_m * $b_length_m;

				if ( $plank_m2 <= 0 ) {
					return [ 'error' => __( 'Kon plankoppervlakte niet berekenen voor visgraat.', 'hh-decking-calc' ) ];
				}

				$raw_qty = $surface_m2 / $plank_m2;
				$total_qty = (int) ceil( $raw_qty * $waste_multiplier );

				$total_planks_qty = $total_qty;
				$total_rows = 0; 

				$waste_txt = ( $surface_m2 < 15 ) ? '5%' : '3%';

				$lines[] = [
					'type'       => 'simple',
					'product_id' => $map['product'],
					'qty'        => $total_qty,
					'meta'       => [
						'_hh_dc_summary' => sprintf(
							__( '%s — %d stuks (Opp: %.2fm², Plank: %dx%dmm, Waste: %s)', 'hh-decking-calc' ),
							$map['label'],
							$total_qty,
							$surface_m2,
							$b_width_mm,
							$b_length_mm,
							$waste_txt
						),
					],
				];

			} else {
				// --- SUB-CASE: Standaard Vlonderplank ---
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
		}

		// =========================================================
		// CASE D: Composiet — (Versie 2.0: Best fit OR 70/30)
		// =========================================================
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
		$regels = self::calc_regels( $len_m, $type, $map, $poles );

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

		// === Accessoires stap 6: OLIE (Bamboe) ===
		if ( $type === 'bamboe' ) {
			$olie = self::calc_olie( $surface_m2 );
			foreach ( $olie as $item ) {
				$lines[] = [
					'type'       => 'simple',
					'product_id' => $item['product_id'],
					'qty'        => $item['qty'],
					'meta'       => [ '_hh_dc_summary' => $item['_hh_dc_summary'] ],
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

		// SCENARIO 0: Past in één lengte?
		if ( $len_m <= $longest_m ) {
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

		// SCENARIO 1: Zeer lang terras (> 2x langste plank)
		if ( $len_m > ( 2 * $longest_m ) ) {
			$add( $longest_mm, $rows );
			$rest_m = $len_m - $longest_m;
			$explain[] = sprintf('Lengte > 2x max: 1x %dmm per rij, rest %.2fm', $longest_mm, $rest_m);
			$len_m = $rest_m; 
			$target_mm = (int) round($len_m * 1000);
		}

		// SCENARIO 2: 70/30 (of Max-Strat)
		$ideal_70_mm = $target_mm * 0.7;

		if ( $ideal_70_mm > $longest_mm ) {
			$add( $longest_mm, $rows );
			$remainder_mm = $target_mm - $longest_mm;
			
			if ( $remainder_mm > 0 ) {
				$pair_target_m = ($remainder_mm * 2) / 1000;
				$L_pair_mm = Calculator::ceil_length( $pair_target_m, $lengths, false );

				if ( $L_pair_mm > 0 ) {
					$qty_pairs = (int) ceil( $rows / 2 );
					$add( $L_pair_mm, $qty_pairs );
					$explain[] = sprintf('Max-Strat: 1x %dmm + Rest (paren in %dmm)', $longest_mm, $L_pair_mm);
				} else {
					$L_single_mm = Calculator::ceil_length( $remainder_mm / 1000, $lengths );
					if ( $L_single_mm == 0 ) $L_single_mm = $longest_mm; 
					$add( $L_single_mm, $rows );
					$explain[] = sprintf('Max-Strat: 1x %dmm + Rest (enkel %dmm)', $longest_mm, $L_single_mm);
				}
			}

		} else {
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

	private static function calculate_bamboe_planken( array $map, float $len_m, float $wid_m ): array {
		$width_mm = (int) ( $map['width_mm'] ?? 0 );
		if ( $width_mm <= 0 ) {
			return ['qty' => 0, 'rows' => 0, 'board_len_mm' => 0, 'explain' => __( 'Ongeldige bamboe-configuratie.', 'hh-decking-calc' ) ];
		}

		$spacing_mm      = 6;
		$row_width_m     = ( $width_mm + $spacing_mm ) / 1000;
		$rows            = (int) ceil( $wid_m / $row_width_m );
		$board_len_mm    = (int) ( $map['product_length_mm'] ?? 1860 );
		$board_len_m     = $board_len_mm / 1000;

		$total_run_m     = ( $len_m * $wid_m ) / $row_width_m;
		$boards_float    = $total_run_m / $board_len_m;
		$boards_with_waste = ceil( $boards_float * 1.03 );

		return [
			'qty'          => (int) $boards_with_waste,
			'rows'         => $rows,
			'board_len_mm' => $board_len_mm,
			'explain'      => sprintf('rijbreedte: %.3fm, rijen: %d, +3%% zaagverlies', $row_width_m, $rows),
		];
	}

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

		if ( $len_m <= $longest_m ) {
			$best_fit_mm = self::ceil_length( $len_m, $lengths );
			$by_length[$best_fit_mm] = $rows;
			$explain = sprintf('Lengte %.2fm ≤ %.2fm → alleen %dmm × %d', $len_m, $longest_m, $best_fit_mm, $rows);
		}
		else {
			$shortest_mm = reset( $lengths );
			$by_length[$longest_mm] = $rows;
			$qty_pairs = (int) ceil( $rows / 2 ); 
			$by_length[$shortest_mm] = $qty_pairs;
			$explain = sprintf('70/30: %dmm × %d + %dmm × %d (paren)', $longest_mm, $rows, $shortest_mm, $qty_pairs);
		}

		return [
			'by_length' => $by_length,
			'total_qty' => (int) array_sum( $by_length ),
			'rows'      => $rows,
			'explain'   => $explain,
		];
	}

	// ... [De methodes calc_regels, calc_piketpalen, calc_schroeven, calc_slotbouten, calc_clips blijven ongewijzigd, maar voor volledigheid moeten ze erin staan] ...
    // Voor de output in dit venster kort ik ze af, maar in het bestand moet je de originele code behouden.
    // Ik plak ze hieronder in voor de zekerheid.

	private static function calc_regels( float $len_m, string $type, array $map, string $poles ): ?array {
		if ( $len_m <= 0 ) return null;
		$regels_qty = (int) ceil( $len_m / 0.5 ) + 1;

		if ( $poles === 'with' ) {
			$acc_cfg = CONFIG['accessories']['regels'] ?? null;
			$product_id = $acc_cfg['product_ids'][0] ?? 0;
			return ['qty' => $regels_qty, 'product_id' => (int) $product_id, 'label' => 'Bangkirai regel 40x60', '_hh_dc_summary' => sprintf('Regels: %d stuks — Bangkirai (tuin/piketpalen)', $regels_qty)];
		}

		$subtype_map = $map['subtype'] ?? '';
		if ( $type === 'hout' && $subtype_map === 'douglas' && isset( CONFIG['mappings']['regel_douglas_44x70'] ) ) {
			$regel_cfg = CONFIG['mappings']['regel_douglas_44x70'];
			return ['qty' => $regels_qty, 'product_id' => (int) $regel_cfg['product'], 'label' => $regel_cfg['label'], '_hh_dc_summary' => sprintf('Regels: %d stuks — %s (balkon)', $regels_qty, $regel_cfg['label'])];
		}
		if ( $type === 'composiet' && isset( CONFIG['mappings']['regel_hhline_25x40'] ) ) {
			$regel_cfg = CONFIG['mappings']['regel_hhline_25x40'];
			return ['qty' => $regels_qty, 'product_id' => (int) $regel_cfg['product'], 'label' => $regel_cfg['label'], '_hh_dc_summary' => sprintf('Regels: %d stuks — %s (balkon, 2200mm)', $regels_qty, $regel_cfg['label'])];
		}

		$cfg = CONFIG['accessories']['regels'] ?? null;
		if ( ! $cfg || empty( $cfg['product_ids'] ) ) return null;
		
		// Fallback logic
		$product_id = match($type) { 'hout' => $cfg['product_ids'][1] ?? $cfg['product_ids'][0], default => $cfg['product_ids'][0] };
		return ['qty' => $regels_qty, 'product_id' => (int) $product_id, 'label' => $cfg['label'] ?? 'Regels', '_hh_dc_summary' => sprintf('Regels: %d stuks', $regels_qty)];
	}

	private static function calc_piketpalen( int $regels_qty, float $wid_m, string $pole_size ): ?array {
		if ( $regels_qty <= 0 || $wid_m <= 0 ) return null;
		$cfg = CONFIG['accessories']['piketpalen'] ?? null;
		if ( ! $cfg || empty( $cfg['product_ids'] ) ) return null;

		$palen_qty = $regels_qty * ( (int) ceil( $wid_m / 1.0 ) + 1 );
		$product_id = match ( $pole_size ) { '50x50' => $cfg['product_ids'][1] ?? $cfg['product_ids'][0], default => $cfg['product_ids'][0] };

		return ['qty' => $palen_qty, 'product_id' => (int) $product_id, 'label' => $cfg['label'] ?? 'Piketpalen', '_hh_dc_summary' => sprintf( 'Piketpalen: %d stuks — %s mm', $palen_qty, $pole_size )];
	}

	private static function calc_schroeven( int $height_mm, int $plank_qty, int $regels_qty, int $rows ): ?array {
		if ( $plank_qty <= 0 || $regels_qty <= 0 ) return null;
		$cfg = CONFIG['accessories']['hhline_rvs_hardhout_vlonderschroef'] ?? null;
		if ( ! $cfg ) return null;

		$variation_key = match ( $height_mm ) { 21 => '5.5x40', 25 => '5.5x50', default => '5.5x60' };
		$variation_id = $cfg['variations'][ $variation_key ] ?? 0;
		if ( $variation_id <= 0 ) return null;

		$total_screws = ( ( $rows * $regels_qty ) * 2 ) + ( max( 0, $plank_qty - $rows ) * 2 );
		$dozen = (int) ceil( $total_screws / 200 );

		return ['qty' => $dozen, 'product_id' => (int) $cfg['product_id'], 'variation_id' => (int) $variation_id, 'label' => $cfg['label'], '_hh_dc_summary'=> sprintf('%s — %d doos/dozen (%s)', $cfg['label'], $dozen, $variation_key)];
	}

	/**
	 * Bereken slotbouten (alleen bij tuin/piketpalen)
	 * UPDATE: Altijd uitgaan van dikste regel (45mm) voor veiligheid en eenvoud.
	 */
	private static function calc_slotbouten( int $palen_qty, string $regel_label, string $pole_size ): ?array {
		if ( $palen_qty <= 0 ) return null;
		$cfg = CONFIG['accessories']['slotbouten'] ?? null;
		if ( ! $cfg ) return null;

		// Bepaal afmetingen
		// Piketpaal "40x40" -> 40, "50x50" -> 50
		$paal_mm = (strpos($pole_size, '50') !== false) ? 50 : 40;

		// Regel: We gaan altijd uit van de dikste maat (Angelim = 44mm -> pak 45mm voor veiligheid) 
		// om altijd uit te komen met de boutlengte, ongeacht welk type hout er gekozen is.
		$regel_mm = 45; 

		// Formule: Paal + Regel + 15mm (moer/ring/buffer)
		$benodigde_lengte_mm = $paal_mm + $regel_mm + 15;

		// Afronden naar dichtstbijzijnde maat (bijv 10-tallen: 95->100, 109->110)
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

	private static function calc_clips( int $plank_qty, int $regels_qty, int $rows, string $subtype ): array {
		$out = [];
		$pack_size = 100; 
		$cfg_middle = CONFIG['accessories']['tussenclips'] ?? null;
		
		if ( $cfg_middle && ! empty( $cfg_middle['product_id'] ) ) {
			if ( $subtype === 'visgraat' ) {
				$total_clips = $plank_qty * 4;
				$dozen = (int) ceil( $total_clips / $pack_size );
				$summary = sprintf('Visgraat clips: %d planken x 4 = %d stuks', $plank_qty, $total_clips);
			} else {
				$total_clips = $rows * $regels_qty;
				$dozen = (int) ceil( $total_clips / $pack_size );
				$summary = sprintf('Clips: %d rijen x %d regels = %d stuks', $rows, $regels_qty, $total_clips);
			}
			$out[] = ['product_id' => (int) $cfg_middle['product_id'], 'qty' => $dozen, '_hh_dc_summary' => sprintf('%s — %d doos/dozen (%s)', $cfg_middle['label'], $dozen, $summary)];
		}
		
		$cfg_start  = CONFIG['accessories']['startclips'] ?? null;
		if ( $cfg_start && ! empty( $cfg_start['product_id'] ) && $subtype !== 'visgraat' ) {
			$out[] = ['product_id' => (int) $cfg_start['product_id'], 'qty' => $regels_qty, '_hh_dc_summary' => sprintf('%s — %d stuks', $cfg_start['label'], $regels_qty)];
		}
		return $out;
	}

	/**
	 * Bereken Bamboe Olie
	 * Formule: m2 / 15 = aantal potjes (0,75L).
	 * Elke 3 potjes (0,75L) worden 1 grote pot (2,5L).
	 */
	private static function calc_olie( float $surface_m2 ): array {
		if ( $surface_m2 <= 0 ) return [];

		// Basis: aantal kleine potjes (0.75L)
		$total_small_needed = (int) ceil( $surface_m2 / 15 );

		// Optimalisatie: wissel elke 3 kleine in voor 1 grote (2.5L)
		$large_qty = (int) floor( $total_small_needed / 3 );
		$small_qty = $total_small_needed % 3;

		$out = [];

		// LET OP: Dit zijn placeholder ID's voor de "verborgen" producten.
		// Maak twee simpele (hidden) producten aan in WooCommerce:
		// 1. Saicos Decking Oil 0.75L Kleurloos -> Vul ID hieronder in
		// 2. Saicos Decking Oil 2.5L Kleurloos  -> Vul ID hieronder in
		$id_075 = 999075; 
		$id_250 = 999250; 

		if ( $large_qty > 0 ) {
			$out[] = [
				'product_id' => $id_250,
				'qty'        => $large_qty,
				'_hh_dc_summary' => sprintf(
					__( 'Saicos Decking Oil (2,5L) — %d pot(ten) (optimaal voor ca. %d m²)', 'hh-decking-calc' ),
					$large_qty,
					$large_qty * 3 * 15 
				),
			];
		}

		if ( $small_qty > 0 ) {
			$out[] = [
				'product_id' => $id_075,
				'qty'        => $small_qty,
				'_hh_dc_summary' => sprintf(
					__( 'Saicos Decking Oil (0,75L) — %d pot(ten)', 'hh-decking-calc' ),
					$small_qty
				),
			];
		}

		return $out;
	}

	/**
	 * Vind de juiste mapping uit CONFIG.
	 * UPDATE voor Tegels: Als subtype 'tegel' is, negeren we de height-check als de mapping height 0 is.
	 */
	private static function find_mapping( string $type, int $height, string $color = '', string $subtype = '' ): ?array {
		foreach ( CONFIG['mappings'] as $map ) {
			if ( $map['type'] !== $type ) continue;
			if ( $subtype && isset( $map['subtype'] ) && $map['subtype'] !== $subtype ) continue;
			
			// Color check
			if ( isset( $map['color'] ) && $map['color'] && $map['color'] !== $color ) continue;

			// Height check (speciaal voor tegels die 0 kunnen zijn in config)
			if ( isset( $map['thick_mm'] ) ) {
				if ( $subtype === 'tegel' ) {
					// Voor tegels negeren we de input-height als de config 0 zegt,
					// OF we checken of de input overeenkomt als de config > 0 is.
					// Omdat wizard.js vaak height verbergt voor tegels, kan $height 0 zijn.
				} else {
					if ( (int) $map['thick_mm'] !== (int) $height ) continue;
				}
			}

			return $map;
		}
		return null;
	}
}