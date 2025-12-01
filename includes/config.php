<?php
namespace HH\DeckingCalc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optie 1 – alle koppelingen & regels in code.
 * LET OP:
 * - 'type' moet één van: bamboe | hout | composiet
 * - 'color' alleen invullen waar kleur een eigen basisproduct is (bamboe/composiet)
 * - Hout heeft geen kleur (weglaten).
 * - Variabele producten: vul 'length_variations' met lengte_in_mm => variation_id
 * - Simpele producten: alleen 'product' invullen.
 */
const CONFIG = [
	'defaults' => [
		// Standaard zaagverlies/waste in %
		'waste_percent' => 10,
	],

	'mappings' => [

		// =========================
		// HOUT (variabele producten)
		// =========================

		// Bangkirai 21x145 (voorbeeld-IDs uit je sheet — verifieer even in WP)
		'bangkirai_21x145' => [
			'type'      => 'hout',
			'subtype' => 'bangkirai',
			'label'     => 'Bangkirai 21x145',
			'width_mm'  => 145,
			'thick_mm'  => 21,          // HOOGTE bepaalt keuze tussen 21/25/27
			'product'   => 1029,       // hoofdproduct-id
			'length_variations' => [     // lengte in mm => variation_id
				2300 => 4576,
				2450 => 1545,
				3650 => 1544,
				3950 => 4093,
				4300 => 4094,
				4600 => 4095,
				4900 => 4096,
			],
		],

		'bangkirai_25x145' => [
			'type'      => 'hout',
			'subtype' => 'bangkirai',
			'label'     => 'Bangkirai 25x145',
			'width_mm'  => 145,
			'thick_mm'  => 25,
			'product'   => 1030,
			'length_variations' => [
				2450 => 3815,
				2750 => 3814,
				3350 => 3813,
				3650 => 3812,
				4300 => 1552,
				4600 => 1553,
				4900 => 1554, // checken of dit klopt t.o.v. 21x145; vul anders juiste var-ID in
			],
		],

		'bangkirai_27x190' => [
			'type'      => 'hout',
			'subtype' => 'bangkirai',
			'label'     => 'Bangkirai 27x190',
			'width_mm'  => 190,
			'thick_mm'  => 27,
			'product'   => 1031,
			'length_variations' => [
				2450 => 4097,
				2750 => 3820,
				3050 => 3819,
				3350 => 3818,
				3650 => 3817,
				3950 => 3816,
				4600 => 1555,
				3900 => 1558,
			],
		],

		'angelim_43x140' => [
			'type'      => 'hout',
			'subtype' => 'angelim',
			'label'     => 'Angelim Vermelho 43x140',
			'width_mm'  => 140,
			'thick_mm'  => 43,
			'product'   => 4111,
			'length_variations' => [
				2500 => 4112,
				3000 => 4113,
				4000 => 4114,
				4500 => 4115,
			],
		],

		'angelim_43x190' => [
			'type'      => 'hout',
			'subtype' => 'angelim',
			'label'     => 'Angelim Vermelho 43x190',
			'width_mm'  => 190,
			'thick_mm'  => 43,
			'product'   => 4116,
			'length_variations' => [
				2500 => 4117,
				3000 => 4118,
				4000 => 4119,
			],
		],

		'douglas_28x195' => [
			'type'      => 'hout',
			'subtype' => 'douglas',
			'label'     => 'Douglas 28x195',
			'width_mm'  => 195,
			'thick_mm'  => 28,
			'product'   => 955,
			'length_variations' => [
				3000 => 957,
				4000 => 958,
				5000 => 956,
			],
		],

		'douglas_24x138' => [
			'type'      => 'hout',
			'subtype' => 'douglas',
			'label'     => 'Douglas 24x138',
			'width_mm'  => 138,
			'thick_mm'  => 24,
			'product'   => 998,
			'length_variations' => [
				3000 => 1581,
				4000 => 999,
				5000 => 1000,
			],
		],


		// =============================
		// COMPOSIET (variabele producten)
		// Kleur = basisproduct (color), variatie = lengte
		// =============================

		'hhline_composiet_stone_grey_23x140' => [
			'type'      => 'composiet',
			'color'     => 'stone_grey',
			'label'     => 'HHLine Composiet Stone Grey 23x140',
			'width_mm'  => 140,
			'thick_mm'  => 23,
			'product'   => 2850,
			'length_variations' => [
				2900 => 2851,
				4000 => 2852,     // TODO: vul variation_id voor 4000mm in (indien aanwezig)
			],
		],

		'hhline_composiet_ipe_23x140' => [
			'type'      => 'composiet',
			'color'     => 'ipe',
			'label'     => 'HHLine Composiet Ipe 23x140',
			'width_mm'  => 140,
			'thick_mm'  => 23,
			'product'   => 2847,
			'length_variations' => [
				2900 => 2848,
				4000 => 2849,     // TODO
			],
		],

		'hhline_composiet_teak_23x140' => [
			'type'      => 'composiet',
			'color'     => 'teak',
			'label'     => 'HHLine Composiet Teak 23x140',
			'width_mm'  => 140,
			'thick_mm'  => 23,
			'product'   => 4208,
			'length_variations' => [
				2900 => 4209,
				4000 => 4210,     // TODO
			],
		],

		'hhline_composiet_ebony_23x140' => [
			'type'      => 'composiet',
			'color'     => 'ebony',
			'label'     => 'HHLine Composiet Ebony 23x140',
			'width_mm'  => 140,
			'thick_mm'  => 23,
			'product'   => 2844,
			'length_variations' => [
				2900 => 2845,
				4000 => 2846,     // TODO
			],
		],


		// =========================
		// HHLINE BAMBOE (simpel)
		// =========================

		// Vlonderplanken
		'hhline_bamboe_plank_espresso_18x140x1860' => [
			'type'      => 'bamboe',
			'subtype'   => 'plank',
			'color'     => 'espresso',
			'label'     => 'HHLine Bamboe Vlonderplank Espresso 18x140x1860',
			'width_mm'  => 140,
			'thick_mm'  => 18,
			'product'   => 2674,
		],
		'hhline_bamboe_plank_ebony_18x140x1860' => [
			'type'      => 'bamboe',
			'subtype'   => 'plank',
			'color'     => 'ebony',
			'label'     => 'HHLine Bamboe Vlonderplank Ebony 18x140x1860',
			'width_mm'  => 140,
			'thick_mm'  => 18,
			'product'   => 2675,
		],
		'hhline_bamboe_plank_espresso_18x200x2000' => [
			'type'      => 'bamboe',
			'subtype'   => 'plank',
			'color'     => 'espresso',
			'label'     => 'HHLine Bamboe Vlonderplank Espresso 18x200x2000',
			'width_mm'  => 200,
			'thick_mm'  => 18,
			'product'   => 4225,
		],

		// Vlondertegels (paneel-varianten)
		'hhline_bamboe_tegel_espresso_0_54m2' => [
			'type'      => 'bamboe',
			'subtype'   => 'tegel',
			'color'     => 'espresso',
			'label'     => 'HHLine Bamboe Vlondertegel Espresso 0,54 m²',
			'width_mm'  => 0,   // voor tegels rekenen we straks anders
			'thick_mm'  => 0,
			'product'   => 4223,
		],
		'hhline_bamboe_tegel_ebony_0_54m2' => [
			'type'      => 'bamboe',
			'subtype'   => 'tegel',
			'color'     => 'ebony',
			'label'     => 'HHLine Bamboe Vlondertegel Ebony 0,54 m²',
			'width_mm'  => 0,
			'thick_mm'  => 0,
			'product'   => 4224,
		],

		// Visgraat
		'hhline_bamboe_visgraat_espresso_18x140x700' => [
			'type'      => 'bamboe',
			'subtype'   => 'visgraat',
			'color'     => 'espresso',
			'label'     => 'HHLine Bamboe Visgraat Espresso 18x140x700',
			'width_mm'  => 140,
			'thick_mm'  => 18,
			'product'   => 3790,
		],
		'hhline_bamboe_visgraat_ebony_18x140x700' => [
			'type'      => 'bamboe',
			'subtype'   => 'visgraat',
			'color'     => 'ebony',
			'label'     => 'HHLine Bamboe Visgraat Ebony 18x140x700',
			'width_mm'  => 140,
			'thick_mm'  => 18,
			'product'   => 3791,
		],

		// =========================
		// REGELS (balkon)
		// =========================

		'regel_douglas_44x70' => [
			'type'      => 'hout',
			'subtype'   => 'regel_douglas',
			'label'     => 'Douglas Regel 44x70',
			'width_mm'  => 44,
			'thick_mm'  => 70,
			'product'   => 964, // TODO: vul hoofdproduct-id in
			'length_variations' => [
				// TODO: vul lengte_in_mm => variation_id in op basis van de 4 variaties
				2500 => 3936,
				3000 => 965,
				4000 => 973,
				5000 => 3935,
			],
		],

		'regel_hhline_25x40' => [
			'type'      => 'composiet',
			'subtype'   => 'regel_hhline',
			'label'     => 'HHLine Regel 25x40',
			'width_mm'  => 40,
			'thick_mm'  => 25,
			'product'   => 2857,
			'product_length_mm' => 2200, // vaste lengte 2200mm (info voor jezelf / evt. latere logica)
		],
	],

	// Accessoires doen we later (op basis van Excel-regels)
	'accessories' => [

	// ===== Universeel (voor alle types, keuzeopties) =====
	'piketpalen' => [
		'label'       => 'Piketpalen',
		'product_ids' => [
			1068, // Angelim Vermelho 40x40x1000mm Ruw
			3597, // Angelim Vermelho 50x50x1000mm Ruw (gebruikt enkel 100cm variant)
		],
		'applicable'  => ['hout', 'bamboe', 'composiet'], // keuzemateriaal
		'rule'        => 'manual', // handmatige keuze, geen automatische berekening
	],

	'regels' => [
		'label'       => 'Regels',
		'product_ids' => [
			4206, // Bangkirai verlijmd 40x60mm
			1064, // Angelim Vermelho 44x70mm Geschaafd
		],
		'applicable'  => ['hout', 'bamboe', 'composiet'],
		'rule'        => 'manual',
	],

	// ===== Schroeven (automatisch toegevoegd) =====
	'hhline_rvs_hardhout_vlonderschroef' => [
		'label'      => 'HHLine RVS Hardhout Vlonderschroef',
		'product_id' => 709, // hoofdproduct
		'variations' => [
			'5.5x40' => 713, // bij 21x145
			'5.5x50' => 712, // bij 25x145
			'5.5x60' => 711, // bij 27x190
		],
		'applicable' => ['hout'],
		'rule'       => [
			'type' => 'auto',
			'per_m2' => 1, // of andere logica later in Excel
		],
	],

	// ===== Clips =====
	'startclips' => [
		'label'      => 'HHLine startclips',
		'product_id' => 2858,
		'applicable' => ['bamboe', 'composiet'],
		'rule'       => [
			'type'     => 'auto',
			'per_board' => 1, // bijvoorbeeld 1 startclip per rij
		],
	],

	'eindclips' => [
		'label'      => 'HHLine eindclips',
		'product_id' => 2858, // zelfde ID of andere? checken
		'applicable' => ['bamboe', 'composiet'],
		'rule'       => [
			'type'     => 'auto',
			'per_board' => 1,
		],
	],

	'tussenclips' => [
		'label'      => 'HHLine tussenclips',
		'product_id' => 2859,
		'applicable' => ['bamboe', 'composiet'],
		'rule'       => [
			'type'      => 'auto',
			'per_board' => 20, // bijv. 20 clips per plank
		],
	],

	'slotbouten' => [
		'label'       => 'HHLine Slotbouten',
		'product_id'  => 0, // TODO: vul echt product-ID in
		'applicable'  => ['hout', 'bamboe', 'composiet'],
		'rule'        => 'auto',
	],
],

];