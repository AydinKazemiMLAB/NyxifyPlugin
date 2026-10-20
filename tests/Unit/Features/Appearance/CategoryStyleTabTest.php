<?php
/**
 * Tests for the "Categories" tab.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Appearance;

use Brain\Monkey\Functions;
use Nyxify\Features\Appearance\AppearanceSaveHandler;
use Nyxify\Features\Appearance\Tabs\CategoryStyleTab;
use Nyxify\Features\Appearance\Tabs\Fields;
use Nyxify\Tests\Unit\TestCase;

/**
 * The shop's product categories as a tree, each with an icon and a color: read, checked as the core checks them, and
 * printed back.
 */
final class CategoryStyleTabTest extends TestCase {

	/**
	 * The shop's categories, by name as WordPress returns them: "Bags" is under "Women", "Lost" under a parent that
	 * isn't there.
	 */
	private const CATEGORIES = array(
		array(
			'id'     => 12,
			'parent' => 10,
			'name'   => 'Bags',
		),
		array(
			'id'     => 30,
			'parent' => 99,
			'name'   => 'Lost',
		),
		array(
			'id'     => 20,
			'parent' => 0,
			'name'   => 'Men',
		),
		array(
			'id'     => 10,
			'parent' => 0,
			'name'   => 'Women',
		),
	);

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'number_format_i18n' )->alias( fn( $number ): string => (string) $number );
		Functions\when( 'determine_locale' )->justReturn( 'en_US' );
		Functions\when( 'sanitize_text_field' )->alias( fn( string $value ): string => trim( (string) preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- A stand-in for WordPress.
		Functions\when( 'selected' )->alias( fn( $selected, $current ): string => (string) $selected === (string) $current ? " selected='selected'" : '' );
	}

	public function test_the_tab_is_the_category_style_part(): void {
		self::assertSame( 'category_style', self::tab()->part() );
		self::assertSame( 'Categories', self::tab()->label() );
	}

	public function test_the_form_lists_each_category_after_its_parent(): void {
		self::assertSame(
			array(
				'30.icon.emoji',
				'30.icon.custom_emoji_id',
				'30.style',
				'20.icon.emoji',
				'20.icon.custom_emoji_id',
				'20.style',
				'10.icon.emoji',
				'10.icon.custom_emoji_id',
				'10.style',
				'12.icon.emoji',
				'12.icon.custom_emoji_id',
				'12.style',
			),
			array_keys( self::tab()->values( null ) )
		);
	}

	public function test_the_stored_part_fills_the_form_and_reads_back_the_same(): void {
		$stored = array(
			'10' => array(
				'icon'  => array(
					'emoji'           => '👗',
					'custom_emoji_id' => '5368324170671202286',
				),
				'style' => 'success',
			),
			'12' => array( 'style' => 'danger' ),
		);
		$tab    = self::tab();
		$values = $tab->values( $stored );

		self::assertSame( '👗', $values['10.icon.emoji'] );
		self::assertSame( 'danger', $values['12.style'] );
		self::assertSame( '', $values['20.style'] );

		$read = $tab->read( self::posted( $values ) );

		self::assertSame( array(), $read->errors );
		self::assertSame( $stored, $read->part );
	}

	public function test_an_empty_form_removes_the_part(): void {
		$read = self::tab()->read( self::posted( self::tab()->values( null ) ) );

		self::assertSame( array(), $read->errors );
		self::assertNull( $read->part );
	}

	public function test_a_deleted_categorys_look_is_not_kept(): void {
		$tab    = self::tab();
		$values = $tab->values(
			array(
				'77' => array( 'style' => 'primary' ),
				'20' => array( 'style' => 'primary' ),
			)
		);

		self::assertArrayNotHasKey( '77.style', $values );
		self::assertSame( array( '20' => array( 'style' => 'primary' ) ), $tab->read( self::posted( $values ) )->part );
	}

	public function test_a_pasted_id_and_emoji_is_split_and_a_bad_icon_is_marked(): void {
		$values                  = self::tab()->values( null );
		$values['10.icon.emoji'] = '5368324170671202286 👗';
		$values['20.icon.emoji'] = 'Men';
		$values['12.style']      = 'purple';

		$read = self::tab()->read( self::posted( $values ) );

		self::assertSame( '5368324170671202286', $read->values['10.icon.custom_emoji_id'] );
		self::assertSame(
			array(
				'20.icon.emoji' => Fields::INVALID,
				'12.style'      => Fields::INVALID,
			),
			$read->errors
		);
	}

	public function test_a_form_cut_short_by_the_server_saves_nothing(): void {
		$posted = self::posted( self::tab()->values( null ) );
		unset( $posted[ CategoryStyleTab::COMPLETE ] );

		self::assertSame( array( '' => CategoryStyleTab::TRUNCATED ), self::tab()->read( $posted )->errors );
		self::assertStringContainsString( 'max_input_vars', CategoryStyleTab::error_message( '', CategoryStyleTab::TRUNCATED ) );
	}

	public function test_categories_that_cant_be_read_save_nothing(): void {
		$tab = new CategoryStyleTab( fn(): ?array => null );

		self::assertSame( array(), $tab->values( array( '10' => array( 'style' => 'primary' ) ) ) );
		self::assertSame( array( '' => CategoryStyleTab::UNAVAILABLE ), $tab->read( array( CategoryStyleTab::COMPLETE => '1' ) )->errors );
		self::assertStringContainsString( 'couldn’t be read', self::render( $tab, array(), array(), null ) );
	}

	public function test_more_looks_than_the_core_takes_are_refused(): void {
		$categories = array();
		$posted     = array( CategoryStyleTab::COMPLETE => '1' );
		for ( $id = 1; $id <= CategoryStyleTab::MAX_STYLES + 1; $id++ ) {
			$categories[]  = array(
				'id'     => $id,
				'parent' => 0,
				'name'   => 'C' . $id,
			);
			$posted[ $id ] = array( 'style' => 'primary' );
		}

		$read = ( new CategoryStyleTab( fn(): array => $categories ) )->read( $posted );

		self::assertSame( array( '' => CategoryStyleTab::TOO_MANY ), $read->errors );
		self::assertSame( 'Up to 500 categories can have their own icon or color.', CategoryStyleTab::error_message( '', CategoryStyleTab::TOO_MANY ) );
	}

	public function test_messages_follow_the_field(): void {
		self::assertSame( 'Choose a color from the list.', CategoryStyleTab::error_message( '12.style', Fields::INVALID ) );
		self::assertSame( 'A premium emoji’s id: up to 20 digits.', CategoryStyleTab::error_message( '12.icon.custom_emoji_id', Fields::INVALID ) );
		self::assertSame( 'The Nyxify server did not accept this value.', CategoryStyleTab::error_message( '12.icon.emoji', Fields::REJECTED ) );
		self::assertSame( 'The Nyxify server did not accept these details. Check them and save again.', CategoryStyleTab::error_message( '', Fields::REJECTED ) );
	}

	public function test_the_cores_refusal_marks_the_categorys_field(): void {
		self::assertSame(
			array( '10.icon.emoji' => Fields::REJECTED ),
			AppearanceSaveHandler::refused_fields( 'category_style', array( 'body.settings.category_style.10.icon.emoji' ) )
		);
	}

	public function test_render_prints_a_row_per_category_indented_under_its_parent(): void {
		$tab  = self::tab();
		$html = self::render( $tab, $tab->values( array( '12' => array( 'style' => 'danger' ) ) ), array(), null );

		self::assertSame( 4, substr_count( $html, '<th scope="row"' ) );
		self::assertLessThan( strpos( $html, '>Bags<' ), (int) strpos( $html, '>Women<' ) );
		self::assertStringContainsString( 'style="--nyxify-depth: 1" dir="auto">Bags<', $html );
		self::assertStringContainsString( 'name="nyxify_category_style[12][style]"', $html );
		self::assertStringContainsString( 'aria-label="Color of Bags"', $html );
		self::assertStringContainsString( "<option value=\"danger\" selected='selected'>Red</option>", $html );
		self::assertStringContainsString( 'name="nyxify_category_style[complete]" value="1"', $html );
		self::assertGreaterThan( strrpos( $html, '</table>' ), strpos( $html, '[complete]' ) );
	}

	public function test_render_marks_errors_and_shows_saved_values_after_a_conflict(): void {
		$tab              = self::tab();
		$mine             = $tab->values( null );
		$mine['10.style'] = 'success';
		$saved            = $tab->values( array( '10' => array( 'style' => 'danger' ) ) );

		$html = self::render( $tab, $mine, array( '20.icon.emoji' => Fields::INVALID ), $saved );

		self::assertStringContainsString( 'aria-invalid="true"', $html );
		self::assertStringContainsString( 'One emoji, e.g. 🛒', $html );
		self::assertStringContainsString( '<strong>Saved now:</strong> <span dir="auto">Red</span>', $html );
	}

	public function test_a_shop_without_categories_says_so(): void {
		$tab = new CategoryStyleTab( fn(): array => array() );

		self::assertStringContainsString( 'no product categories yet', self::render( $tab, array(), array(), null ) );
		self::assertNull( $tab->read( array( CategoryStyleTab::COMPLETE => '1' ) )->part );
	}

	/**
	 * The tab with the shop's categories.
	 */
	private static function tab(): CategoryStyleTab {
		return new CategoryStyleTab( fn(): array => self::CATEGORIES );
	}

	/**
	 * The form's fields as the browser posts them: nested by key, with the hidden field that closes the form.
	 *
	 * @param array<string, string> $values The form's fields.
	 * @return array<array-key, mixed>
	 */
	private static function posted( array $values ): array {
		$posted = array( CategoryStyleTab::COMPLETE => '1' );
		foreach ( $values as $key => $value ) {
			$posted = self::put( $posted, explode( '.', $key ), $value );
		}

		return $posted;
	}

	/**
	 * A value put at a path of keys.
	 *
	 * @param array<array-key, mixed> $into  The array.
	 * @param list<string>            $path  Keys.
	 * @param string                  $value The value.
	 * @return array<array-key, mixed>
	 */
	private static function put( array $into, array $path, string $value ): array {
		$key = array_shift( $path );
		if ( null === $key ) {
			return $into;
		}
		$into[ $key ] = array() === $path ? $value : self::put( is_array( $into[ $key ] ?? null ) ? $into[ $key ] : array(), $path, $value );

		return $into;
	}

	/**
	 * The tab's fields as printed.
	 *
	 * @param CategoryStyleTab           $tab    The tab.
	 * @param array<string, string>      $values Values.
	 * @param array<string, string>      $errors Errors.
	 * @param array<string, string>|null $saved  Saved values.
	 */
	private static function render( CategoryStyleTab $tab, array $values, array $errors, ?array $saved ): string {
		ob_start();
		$tab->render( $values, $errors, $saved );

		return (string) ob_get_clean();
	}
}
