<?php
// Minimale PHPUnit test (unit) voor mock-berekening.
// Vereist dat je WP-omgeving niet draait; we testen de pure functie benadering.

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/class-calculator.php';

use HH\DeckingCalc\Calculator;

final class CalculatorTest extends TestCase {

	public function test_calculate_with_basic_input(): void {
		$input = array(
			'type'   => 'bamboe',
			'length' => 5.0,
			'width'  => 3.0,
			'color'  => 'naturel',
		);

		$res = Calculator::calculate( $input );

		$this->assertIsArray( $res );
		$this->assertArrayHasKey( 'boards', $res );
		$this->assertArrayHasKey( 'lines', $res );
		$this->assertGreaterThan( 0, $res['boards'] );
		$this->assertNotEmpty( $res['lines'] );
	}
}