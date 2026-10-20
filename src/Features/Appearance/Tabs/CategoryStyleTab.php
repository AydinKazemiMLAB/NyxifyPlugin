<?php
/**
 * The "Categories" tab: an icon and a color per product category.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

use Nyxify\Features\Appearance\TabInput;
use Nyxify\Features\Settings\DateFormat;

/**
 * The `category_style` part (contract §4.3, a `CategoryLook` per WooCommerce category id): the icon and color of a
 * category's button in the customers' product list. The form lists the shop's own `product_cat` tree, a row per
 * category; a category left empty has no icon and no color, as the bots show it by default. Saving writes the looks
 * of the categories the shop has, so a deleted category's look goes with it.
 */
final class CategoryStyleTab implements AppearanceTab {

	public const PART = 'category_style';

	/**
	 * The most categories with a look (the core's limit).
	 */
	public const MAX_STYLES = 500;

	/**
	 * Error codes of the form as a whole.
	 */
	public const UNAVAILABLE = 'categories_unavailable';
	public const TRUNCATED   = 'form_truncated';
	public const TOO_MANY    = 'too_many_categories';

	/**
	 * The hidden field printed after every category's fields: missing when the server dropped the form's end (PHP's
	 * `max_input_vars`), so a category that wasn't read doesn't lose its look.
	 */
	public const COMPLETE = 'complete';

	private const TAXONOMY = 'product_cat';

	/**
	 * Reads the shop's categories.
	 *
	 * @var \Closure(): (list<array{id: int, parent: int, name: string}>|null)
	 */
	private \Closure $categories;

	/**
	 * The categories, once read.
	 *
	 * @var list<array{id: int, name: string, depth: int}>|null
	 */
	private ?array $tree = null;

	/**
	 * Whether the categories were read.
	 *
	 * @var bool
	 */
	private bool $read = false;

	/**
	 * Builds the tab.
	 *
	 * @param \Closure|null $categories Reads the shop's categories (id, parent, name); null when they can't be read.
	 *                                  WordPress's `product_cat` terms by default.
	 *
	 * @phpstan-param (\Closure(): (list<array{id: int, parent: int, name: string}>|null))|null $categories
	 */
	public function __construct( ?\Closure $categories = null ) {
		$this->categories = $categories ?? self::shop_categories( ... );
	}

	/**
	 * The part's name.
	 */
	public function part(): string {
		return self::PART;
	}

	/**
	 * The tab's title.
	 */
	public function label(): string {
		return __( 'Categories', 'nyxify' );
	}

	/**
	 * The form's fields for the stored part: the icon and color of each of the shop's categories.
	 *
	 * @param mixed $stored The part as stored.
	 * @return array<string, string>
	 */
	public function values( mixed $stored ): array {
		$stored = is_array( $stored ) ? $stored : array();
		$values = array();
		foreach ( $this->tree() ?? array() as $category ) {
			$values += ButtonFields::values( $stored[ $category['id'] ] ?? null, $category['id'] . '.', false );
		}

		return $values;
	}

	/**
	 * Reads and checks the posted form.
	 *
	 * @param array<array-key, mixed> $input The tab's fields.
	 */
	public function read( array $input ): TabInput {
		$form = new FormReader( $input );
		$tree = $this->tree();
		if ( null === $tree ) {
			$form->fail( '', self::UNAVAILABLE );
			return $form->input( array() );
		}
		if ( '1' !== Fields::posted( $input, self::COMPLETE ) ) {
			$form->fail( '', self::TRUNCATED );
		}

		$part = array();
		foreach ( $tree as $category ) {
			$look = ButtonFields::read( $form, $category['id'] . '.', false );
			if ( array() !== $look ) {
				$part[ (string) $category['id'] ] = $look;
			}
		}
		if ( count( $part ) > self::MAX_STYLES ) {
			$form->fail( '', self::TOO_MANY );
		}

		return $form->input( $part );
	}

	/**
	 * Prints the fields: a table of the categories, children under their parent.
	 *
	 * @param array<string, string>      $values The fields to show.
	 * @param array<string, string>      $errors Error codes by field key.
	 * @param array<string, string>|null $saved  The saved fields after a conflict.
	 */
	public function render( array $values, array $errors, ?array $saved ): void {
		$field = function ( string $key, array $options = array() ) use ( $values, $errors, $saved ): void {
			Fields::control(
				self::PART,
				$key,
				$values[ $key ] ?? '',
				isset( $errors[ $key ] ) ? self::error_message( $key, $errors[ $key ] ) : null,
				null === $saved ? null : ( $saved[ $key ] ?? '' ),
				$options
			);
		};

		?>
		<p><?php esc_html_e( 'The icon and color of each category’s button in your customers’ product list. A category left empty has no icon and no color, as the bots show categories by default. A deleted category’s icon and color go with it the next time you save.', 'nyxify' ); ?></p>
		<?php
		$tree = $this->tree();
		self::form_error( null === $tree ? array( '' => self::UNAVAILABLE ) : $errors );
		if ( null === $tree ) {
			return;
		}
		if ( array() === $tree ) {
			printf( '<p class="description">%s</p>', esc_html__( 'Your shop has no product categories yet.', 'nyxify' ) );
			return;
		}

		$colors = array( '' => __( 'Default (no color)', 'nyxify' ) ) + ButtonFields::colors();
		?>
		<p class="description"><?php esc_html_e( 'Icon: one emoji. For a Telegram premium emoji, add its id: Bale, Rubika and Telegram apps that can’t show it show the emoji. You can also paste “id emoji” into the emoji box. Color: Telegram only; Bale and Rubika show every button plain.', 'nyxify' ); ?></p>
		<div class="nyxify-categories-wrap">
			<table class="widefat striped nyxify-categories">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Category', 'nyxify' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Icon', 'nyxify' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Premium emoji id (Telegram, optional)', 'nyxify' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Color', 'nyxify' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tree as $category ) : ?>
						<?php $prefix = $category['id'] . '.'; ?>
						<tr>
							<th scope="row" style="<?php echo esc_attr( '--nyxify-depth: ' . min( $category['depth'], 10 ) ); ?>" dir="auto"><?php echo esc_html( $category['name'] ); ?></th>
							<td>
								<?php
								$field(
									$prefix . 'icon.emoji',
									array(
										'class'      => 'nyxify-emoji-input',
										'maxlength'  => 64,
										/* translators: %s: a category's name */
										'aria-label' => sprintf( __( 'Icon of %s', 'nyxify' ), $category['name'] ),
									)
								);
								?>
							</td>
							<td>
								<?php
								$field(
									$prefix . 'icon.custom_emoji_id',
									array(
										'class'      => 'nyxify-id-input',
										'dir'        => 'ltr',
										'maxlength'  => 20,
										/* translators: %s: a category's name */
										'aria-label' => sprintf( __( 'Premium emoji id of %s', 'nyxify' ), $category['name'] ),
									)
								);
								?>
							</td>
							<td>
								<?php
								$field(
									$prefix . 'style',
									array(
										'type'       => 'select',
										'choices'    => $colors,
										/* translators: %s: a category's name */
										'aria-label' => sprintf( __( 'Color of %s', 'nyxify' ), $category['name'] ),
									)
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<input type="hidden" name="<?php echo esc_attr( Fields::name( self::PART, self::COMPLETE ) ); ?>" value="1" />
		<?php
	}

	/**
	 * The message of a field's error code ('' for the form as a whole).
	 *
	 * @param string $key  Field key.
	 * @param string $code Error code.
	 */
	public static function error_message( string $key, string $code ): string {
		if ( '' === $key ) {
			return match ( $code ) {
				self::UNAVAILABLE => __( 'Your product categories couldn’t be read, so nothing was saved. Reload the page and try again.', 'nyxify' ),
				/* translators: %s: PHP's max_input_vars setting */
				self::TRUNCATED   => sprintf( __( 'Your server dropped the end of the form, so nothing was saved: it takes at most %s fields (PHP’s max_input_vars), three per category. Ask your host to raise it, then save again.', 'nyxify' ), self::input_limit() ),
				/* translators: %s: the most categories */
				self::TOO_MANY    => sprintf( __( 'Up to %s categories can have their own icon or color.', 'nyxify' ), DateFormat::number( self::MAX_STYLES ) ),
				default           => __( 'The Nyxify server did not accept these details. Check them and save again.', 'nyxify' ),
			};
		}

		$prefix = (string) preg_replace( '/^(\d+\.).*$/D', '$1', $key );

		return ButtonFields::error_message( $key, $code, $prefix ) ?? __( 'The Nyxify server did not accept this value.', 'nyxify' );
	}

	/**
	 * Prints the error of the form as a whole, when there is one.
	 *
	 * @param array<string, string> $errors Error codes by field key.
	 */
	private static function form_error( array $errors ): void {
		if ( isset( $errors[''] ) ) {
			printf( '<div class="nyxify-callout nyxify-callout--error"><p>%s</p></div>', esc_html( self::error_message( '', $errors[''] ) ) );
		}
	}

	/**
	 * The shop's categories in the order the form lists them: each one followed by its children, by name; null when
	 * they can't be read.
	 *
	 * @return list<array{id: int, name: string, depth: int}>|null
	 */
	private function tree(): ?array {
		if ( $this->read ) {
			return $this->tree;
		}
		$this->read = true;

		$categories = ( $this->categories )();
		if ( null === $categories ) {
			return null;
		}

		$ids      = array_column( $categories, 'id' );
		$children = array();
		foreach ( $categories as $category ) {
			$parent                = in_array( $category['parent'], $ids, true ) ? $category['parent'] : 0;
			$children[ $parent ][] = $category;
		}

		$tree  = array();
		$seen  = array();
		$visit = static function ( int $above, int $depth ) use ( &$visit, &$tree, &$seen, $children ): void {
			foreach ( $children[ $above ] ?? array() as $category ) {
				if ( isset( $seen[ $category['id'] ] ) ) {
					continue;
				}
				$seen[ $category['id'] ] = true;
				$tree[]                  = array(
					'id'    => $category['id'],
					'name'  => $category['name'],
					'depth' => $depth,
				);
				$visit( $category['id'], $depth + 1 );
			}
		};
		$visit( 0, 0 );

		$this->tree = $tree;

		return $tree;
	}

	/**
	 * WordPress's product categories, by name; null when they can't be read.
	 *
	 * @return list<array{id: int, parent: int, name: string}>|null
	 */
	private static function shop_categories(): ?array {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( ! is_array( $terms ) ) {
			return null;
		}

		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'id'     => $term->term_id,
				'parent' => $term->parent,
				'name'   => html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
			);
		}

		return $categories;
	}

	/**
	 * PHP's `max_input_vars`, as a number to show.
	 */
	private static function input_limit(): string {
		$limit = (int) ini_get( 'max_input_vars' );

		return DateFormat::number( $limit > 0 ? $limit : 1000 );
	}
}
