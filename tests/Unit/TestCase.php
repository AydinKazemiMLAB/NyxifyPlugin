<?php
/**
 * Base class for unit tests.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Sets Brain Monkey up and tears it down around every test.
 */
abstract class TestCase extends PHPUnitTestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The core's OpenAPI spec. It is kept with the maintainers' private docs, so tests that compare against it are
	 * skipped where it isn't there (a public clone).
	 *
	 * @return array<string, mixed>
	 */
	protected static function core_spec(): array {
		$path = dirname( __DIR__, 2 ) . '/docs/private/core-api/openapi.json';
		if ( ! is_file( $path ) ) {
			self::markTestSkipped( 'The core\'s OpenAPI spec (docs/private/core-api/openapi.json) is not in this checkout.' );
		}

		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		self::assertIsString( $json );

		$spec = json_decode( $json, true );
		self::assertIsArray( $spec );

		return $spec;
	}
}
