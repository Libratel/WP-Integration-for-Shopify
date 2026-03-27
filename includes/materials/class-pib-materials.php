<?php
/**
 * Partner materials module.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers partner materials content type and scoped download access.
 */
final class PIB_Materials {
	/**
	 * Meta key for material file URL.
	 */
	const META_FILE_URL = '_pib_material_file_url';

	/**
	 * Meta key for allowed company IDs list.
	 */
	const META_COMPANY_IDS = '_pib_material_company_ids';

	/**
	 * Singleton.
	 *
	 * @var PIB_Materials|null
	 */
	private static $instance = null;

	/**
	 * Plugin container.
	 *
	 * @var PIB_Plugin
	 */
	private $plugin;

	/**
	 * Singleton accessor.
	 *
	 * @param PIB_Plugin|null $plugin Plugin container.
	 *
	 * @return PIB_Materials|null
	 */
	public static function instance( $plugin = null ) {
		if ( null === self::$instance ) {
			if ( ! $plugin instanceof PIB_Plugin ) {
				return null;
			}

			self::$instance = new self( $plugin );
		}

		return self::$instance;
	}

	/**
	 * Build object and register hooks.
	 *
	 * @param PIB_Plugin $plugin Plugin container.
	 */
	private function __construct( PIB_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_action( 'save_post_pib_material', array( $this, 'save_material_meta' ) );
		add_action( 'admin_post_nopriv_pib_material_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_pib_material_download', array( $this, 'handle_download' ) );
		add_shortcode( 'portal_materials', array( $this, 'render_materials_shortcode' ) );
	}

	/**
	 * Register material custom post type.
	 *
	 * @return void
	 */
	public function register_post_type() {
		register_post_type(
			'pib_material',
			array(
				'labels'          => array(
					'name'          => 'Partner Materials',
					'singular_name' => 'Partner Material',
					'add_new_item'  => 'Add New Partner Material',
					'edit_item'     => 'Edit Partner Material',
					'view_item'     => 'View Partner Material',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_position'   => 25,
				'menu_icon'       => 'dashicons-media-document',
				'supports'        => array( 'title', 'editor' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Register material metadata box.
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'pib_material_access',
			'Material Access',
			array( $this, 'render_access_meta_box' ),
			'pib_material',
			'normal',
			'default'
		);
	}

	/**
	 * Render access metadata box fields.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return void
	 */
	public function render_access_meta_box( $post ) {
		$file_url    = (string) get_post_meta( $post->ID, self::META_FILE_URL, true );
		$company_ids = (string) get_post_meta( $post->ID, self::META_COMPANY_IDS, true );

		wp_nonce_field( 'pib_material_meta', 'pib_material_meta_nonce' );
		?>
		<p>
			<label for="pib_material_file_url"><strong>File URL</strong></label><br />
			<input type="url" id="pib_material_file_url" name="pib_material_file_url" class="widefat" value="<?php echo esc_attr( $file_url ); ?>" placeholder="https://example.com/files/resource.pdf" />
		</p>
		<p>
			<label for="pib_material_company_ids"><strong>Allowed Company IDs</strong> (optional)</label><br />
			<textarea id="pib_material_company_ids" name="pib_material_company_ids" class="widefat" rows="4" placeholder="company-123&#10;company-456"><?php echo esc_textarea( $company_ids ); ?></textarea>
			<em>Leave empty to allow all authenticated mapped customers.</em>
		</p>
		<?php
	}

	/**
	 * Save material metadata.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public function save_material_meta( $post_id ) {
		if ( ! isset( $_POST['pib_material_meta_nonce'] ) || ! is_scalar( $_POST['pib_material_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['pib_material_meta_nonce'] ) ), 'pib_material_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$file_url = '';
		if ( isset( $_POST['pib_material_file_url'] ) && is_scalar( $_POST['pib_material_file_url'] ) ) {
			$file_url = esc_url_raw( trim( (string) wp_unslash( $_POST['pib_material_file_url'] ) ) );
		}
		$raw_ids = '';
		if ( isset( $_POST['pib_material_company_ids'] ) && is_scalar( $_POST['pib_material_company_ids'] ) ) {
			$raw_ids = (string) wp_unslash( $_POST['pib_material_company_ids'] );
		}
		$list     = $this->sanitize_company_ids( $raw_ids );

		update_post_meta( $post_id, self::META_FILE_URL, $file_url );
		update_post_meta( $post_id, self::META_COMPANY_IDS, implode( "\n", $list ) );
	}

	/**
	 * Render partner materials list.
	 *
	 * @return string
	 */
	public function render_materials_shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<p>Please sign in to view partner materials.</p>';
		}

		$identity = PIB_Authorization::current_identity();
		if ( ! is_array( $identity ) || empty( $identity['shopify_customer_id'] ) ) {
			return '<p>Your portal account is not linked to a customer record yet.</p>';
		}

		$posts = get_posts(
			array(
				'post_type'      => 'pib_material',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		if ( empty( $posts ) ) {
			return '<p>No materials are available at this time.</p>';
		}

		$items = array();
		foreach ( $posts as $post ) {
			if ( ! $this->can_current_user_access_material( $post->ID, $identity ) ) {
				continue;
			}

			$download_url = add_query_arg(
				array(
					'action'      => 'pib_material_download',
					'material_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			);
			$download_url = wp_nonce_url( $download_url, 'pib_material_download_' . $post->ID, 'pib_nonce' );

			$summary = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
			if ( '' === $summary ) {
				$summary = wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 24 );
			}

			$items[] = sprintf(
				'<li><a href="%1$s">%2$s</a>%3$s</li>',
				esc_url( $download_url ),
				esc_html( get_the_title( $post ) ),
				'' !== $summary ? '<br /><small>' . esc_html( $summary ) . '</small>' : ''
			);
		}

		if ( empty( $items ) ) {
			return '<p>No materials are available for your company.</p>';
		}

		return '<ul class="pib-material-list">' . implode( '', $items ) . '</ul>';
	}

	/**
	 * Process secure material download redirect.
	 *
	 * @return void
	 */
	public function handle_download() {
		$material_id = 0;
		if ( isset( $_REQUEST['material_id'] ) && is_scalar( $_REQUEST['material_id'] ) ) {
			$material_id = absint( $_REQUEST['material_id'] );
		}
		$post        = $material_id > 0 ? get_post( $material_id ) : null;

		if ( ! $post || 'pib_material' !== $post->post_type || 'publish' !== $post->post_status ) {
			$this->fail_download( 'pib_material_not_found' );
		}

		$nonce = '';
		if ( isset( $_REQUEST['pib_nonce'] ) && is_scalar( $_REQUEST['pib_nonce'] ) ) {
			$nonce = sanitize_text_field( (string) wp_unslash( $_REQUEST['pib_nonce'] ) );
		}
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'pib_material_download_' . $material_id ) ) {
			$this->fail_download( 'pib_material_nonce_invalid' );
		}

		if ( ! is_user_logged_in() ) {
			$this->redirect_to_signin_for_material();
		}

		$identity = PIB_Authorization::current_identity();
		if ( ! is_array( $identity ) || empty( $identity['shopify_customer_id'] ) ) {
			$this->fail_download( 'pib_missing_identity' );
		}

		if ( ! $this->can_current_user_access_material( $material_id, $identity ) ) {
			$this->fail_download( 'pib_material_forbidden' );
		}

		$file_url = (string) get_post_meta( $material_id, self::META_FILE_URL, true );
		$file_url = esc_url_raw( trim( $file_url ) );

		if ( '' === $file_url || ! wp_http_validate_url( $file_url ) ) {
			$this->fail_download( 'pib_material_file_invalid' );
		}

		do_action( 'pib_material_download', get_current_user_id(), $material_id, $identity );

		wp_redirect( $file_url );
		exit;
	}

	/**
	 * Check current user access to a material.
	 *
	 * @param int                  $material_id Material post ID.
	 * @param array<string, mixed> $identity    Active identity context.
	 *
	 * @return bool
	 */
	private function can_current_user_access_material( $material_id, $identity ) {
		$company_id = isset( $identity['company_id'] ) ? (string) $identity['company_id'] : '';
		$allowed    = $this->get_material_company_ids( $material_id );

		if ( empty( $allowed ) ) {
			return true;
		}

		if ( '' === $company_id ) {
			return false;
		}

		return in_array( $company_id, $allowed, true );
	}

	/**
	 * Load material allowed company IDs.
	 *
	 * @param int $material_id Material post ID.
	 *
	 * @return array<int, string>
	 */
	private function get_material_company_ids( $material_id ) {
		$raw = (string) get_post_meta( $material_id, self::META_COMPANY_IDS, true );
		return $this->sanitize_company_ids( $raw );
	}

	/**
	 * Normalize company ID lists.
	 *
	 * @param string $raw Raw input.
	 *
	 * @return array<int, string>
	 */
	private function sanitize_company_ids( $raw ) {
		$parts = preg_split( '/[\r\n,]+/', (string) $raw );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$ids = array();
		foreach ( $parts as $part ) {
			$id = trim( sanitize_text_field( (string) $part ) );
			if ( '' === $id ) {
				continue;
			}
			$ids[] = $id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Redirect to sign in for protected material access.
	 *
	 * @return void
	 */
	private function redirect_to_signin_for_material() {
		$signin = PIB_Signin_Link::instance( $this->plugin );
		if ( $signin instanceof PIB_Signin_Link ) {
			$url = $signin->build_signin_url( '/materials' );
			if ( '' !== $url ) {
				wp_safe_redirect( $url );
				exit;
			}
		}

		$this->fail_download( 'pib_signin_unavailable' );
	}

	/**
	 * Handle material access failure.
	 *
	 * @param string $error_code Error code.
	 *
	 * @return void
	 */
	private function fail_download( $error_code ) {
		$destination = PIB_Destination::to_absolute_url( '/materials' );
		$destination = add_query_arg(
			array(
				'pib_auth_error' => (string) $error_code,
			),
			$destination
		);

		wp_safe_redirect( $destination );
		exit;
	}
}
