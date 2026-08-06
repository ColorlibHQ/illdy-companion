<?php
/**
 * Admin page for the demo content importer.
 *
 * The importer used to have no UI of its own: it injected markup into the Illdy theme's
 * "Recommended Actions" list and rode the welcome screen's generic AJAX dispatcher. Illdy
 * 2.2.0 removed that onboarding subsystem, so the plugin now owns the whole flow — its
 * own page, its own nonce, its own endpoint — and no longer depends on the theme shipping
 * scaffolding for it.
 *
 * The import itself is unchanged. Every step still runs through
 * Illdy_Companion_Import_Data, so what lands in the database is exactly what it always was.
 *
 * @package illdy-companion
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( ! class_exists( 'Illdy_Companion_Importer_Page' ) ) {

	class Illdy_Companion_Importer_Page {

		/**
		 * Menu slug, also the `page` query arg.
		 */
		const SLUG = 'illdy-import-demo-content';

		/**
		 * Nonce action for the import request.
		 */
		const NONCE = 'illdy_companion_import_demo';

		/**
		 * Capability required to see the page and to run an import.
		 *
		 * Matches what the old welcome-screen endpoint enforced, so this is no weaker
		 * than the flow it replaces.
		 */
		const CAP = 'manage_options';

		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
			add_action( 'wp_ajax_illdy_companion_import_demo', array( $this, 'handle_import' ) );
		}

		/**
		 * The admin-page hook suffix, used to scope asset loading.
		 *
		 * @return string
		 */
		public static function hook_suffix() {
			return 'appearance_page_' . self::SLUG;
		}

		/**
		 * Adds the page under Appearance.
		 */
		public function register_page() {
			add_theme_page(
				esc_html__( 'Import Demo Content', 'illdy-companion' ),
				esc_html__( 'Import Demo Content', 'illdy-companion' ),
				self::CAP,
				self::SLUG,
				array( $this, 'render' )
			);
		}

		/**
		 * Loads the page's script only on the page itself.
		 *
		 * @param string $hook_suffix Current admin page.
		 */
		public function enqueue( $hook_suffix ) {
			if ( self::hook_suffix() !== $hook_suffix ) {
				return;
			}

			wp_enqueue_script(
				'illdy-companion-importer',
				ILLDY_COMPANION_ASSETS_DIR . 'js/importer.js',
				array( 'jquery' ),
				ILLDY_COMPANION,
				true
			);

			wp_localize_script(
				'illdy-companion-importer',
				'illdyCompanionImporter',
				array(
					'ajaxurl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
					'nonce'   => wp_create_nonce( self::NONCE ),
					'i18n'    => array(
						/* translators: shown in a browser confirm() dialog before the import runs. */
						'confirm'   => __( "Importing will overwrite your current Customizer settings and front page widgets.\n\nThis cannot be undone. Continue?", 'illdy-companion' ),
						'running'   => __( 'Importing…', 'illdy-companion' ),
						'button'    => __( 'Import Demo Content', 'illdy-companion' ),
						'noneFound' => __( 'Select at least one item to import.', 'illdy-companion' ),
						'success'   => __( 'Demo content imported.', 'illdy-companion' ),
						'failure'   => __( 'The import did not complete. Nothing further was changed.', 'illdy-companion' ),
					),
				)
			);
		}

		/**
		 * The steps offered on the page, in the order they must run.
		 *
		 * Keys are validated against Illdy_Companion_Import_Data::get_import_steps() on
		 * the server, so this list is presentation only.
		 *
		 * @return array Step id => label.
		 */
		private function steps() {
			return array(
				'set_static_frontpage' => __( 'Set the front page to a static page', 'illdy-companion' ),
				'import_customizer'    => __( 'Import Customizer settings', 'illdy-companion' ),
				'import_widgets'       => __( 'Import front page widgets', 'illdy-companion' ),
			);
		}

		/**
		 * Whether a demo import has already been recorded on this site.
		 *
		 * @return bool
		 */
		private function already_imported() {
			$state = get_option( 'illdy_show_required_actions' );

			return is_array( $state ) && ! empty( $state['illdy-req-import-content'] );
		}

		/**
		 * Renders the page.
		 */
		public function render() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'You do not have permission to import demo content.', 'illdy-companion' ) );
			}
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Import Demo Content', 'illdy-companion' ); ?></h1>

				<p class="description" style="max-width:46em;font-size:14px;">
					<?php esc_html_e( 'This sets up the Illdy front page the way the theme demo looks: it creates the Front Page and Blog pages, fills in the Customizer settings, and adds the front page widgets.', 'illdy-companion' ); ?>
				</p>

				<?php if ( $this->already_imported() ) : ?>
					<div class="notice notice-info inline" style="margin:1em 0;max-width:46em;">
						<p><?php esc_html_e( 'Demo content has already been imported on this site. Running it again will replace your current settings with the demo ones.', 'illdy-companion' ); ?></p>
					</div>
				<?php endif; ?>

				<div class="notice notice-warning inline" style="margin:1em 0;max-width:46em;">
					<p>
						<strong><?php esc_html_e( 'This overwrites existing settings.', 'illdy-companion' ); ?></strong>
						<?php esc_html_e( 'Customizer options and front page widgets are replaced by the demo values. Your posts, pages and media are not touched.', 'illdy-companion' ); ?>
					</p>
				</div>

				<form id="illdy-companion-import-form" method="post" onsubmit="return false;">
					<h2 class="title" style="margin-top:1.5em;"><?php esc_html_e( 'What to import', 'illdy-companion' ); ?></h2>

					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'What to import', 'illdy-companion' ); ?></legend>
						<?php foreach ( $this->steps() as $illdy_step_id => $illdy_step_label ) : ?>
							<p style="margin:.35em 0;">
								<label>
									<input type="checkbox" class="illdy-import-step" value="<?php echo esc_attr( $illdy_step_id ); ?>" checked>
									<?php echo esc_html( $illdy_step_label ); ?>
								</label>
							</p>
						<?php endforeach; ?>
					</fieldset>

					<p class="submit">
						<button type="button" class="button button-primary" id="illdy-run-import">
							<?php esc_html_e( 'Import Demo Content', 'illdy-companion' ); ?>
						</button>
						<span class="spinner" style="float:none;vertical-align:middle;"></span>
					</p>

					<div id="illdy-import-result" role="status" aria-live="polite"></div>
				</form>

				<p>
					<a href="<?php echo esc_url( admin_url( 'customize.php' ) ); ?>">
						<?php esc_html_e( 'Open the Customizer', 'illdy-companion' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		/**
		 * AJAX: runs the requested import steps.
		 */
		public function handle_import() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission to import demo content.', 'illdy-companion' ) ), 403 );
			}

			check_ajax_referer( self::NONCE, 'nonce' );

			$requested = isset( $_POST['steps'] ) ? wp_unslash( $_POST['steps'] ) : array();

			if ( ! is_array( $requested ) ) {
				$requested = array();
			}

			$requested = array_map( 'sanitize_text_field', $requested );

			/*
			 * Intersect with the canonical list rather than trusting the request: the step
			 * name is used to pick a method to call, so it must come from the allowlist.
			 * array_values() keeps it a plain list, and the order comes from the allowlist
			 * so the front page is created before the Customizer settings reference it.
			 */
			$allowed = Illdy_Companion_Import_Data::get_import_steps();
			$steps   = array_values( array_intersect( $allowed, $requested ) );

			if ( empty( $steps ) ) {
				wp_send_json_error( array( 'message' => __( 'Select at least one item to import.', 'illdy-companion' ) ), 400 );
			}

			$result = Illdy_Companion_Import_Data::process_sample_content( $steps );

			if ( 'ok' === $result ) {
				wp_send_json_success(
					array(
						'message' => __( 'Demo content imported.', 'illdy-companion' ),
					)
				);
			}

			wp_send_json_error(
				array(
					'message' => __( 'The import did not complete. Nothing further was changed.', 'illdy-companion' ),
				),
				500
			);
		}
	}

	new Illdy_Companion_Importer_Page();
}
