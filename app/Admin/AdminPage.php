<?php
/**
 * Admin page registration and rendering for the License Server.
 *
 * Registers a "License Server" page under the Tools menu and handles
 * POST actions (delete, bulk-delete) with nonce + capability verification.
 *
 * @package WpLicenseServer\Admin
 */

declare(strict_types=1);

namespace WpLicenseServer\Admin;

use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\LicenseRepository;
use WpLicenseServer\Services\EncryptionService;
use WpLicenseServer\Services\LicenseService;
use WpLicenseServer\Services\TierConfig;

final class AdminPage {

    private const MENU_SLUG = 'wp-license-server';
    private const APP_ROOT_ID = 'wp-license-server-admin-react-root';
    private ?string $hook_suffix = null;
    /**
     * @var array{customer_email: string, customer_name: string, role: string, tier: string, valid_until: string, payment_interval: string, notes: string}
     */
    private array $submitted_create_values = [
        'customer_email'   => '',
        'customer_name'    => '',
        'role'             => 'customer',
        'tier'             => 'pro',
        'valid_until'      => '',
        'payment_interval' => 'yearly',
        'notes'            => '',
    ];
    private ?string $created_license_key = null;
    private ?\WP_Error $create_error = null;

    public function __construct(
        private readonly LicenseRepository $license_repo,
        private readonly ActivationRepository $activation_repo,
        private readonly LicenseService $license_service,
    ) {}

    /**
     * Hooked to admin_menu.
     */
    public function register_menu(): void {
        $this->hook_suffix = add_management_page(
            'License Server',
            'License Server',
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ],
        );

        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Render the main admin page.
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.' ) );
        }

        $this->process_actions();

        echo '<script>(function(){var body=document.body;if(!body){return;}body.classList.add("wp-license-server-admin-js");var restore=function(){var root=document.getElementById("wp-license-server-admin-root");var fallback=document.getElementById("wp-license-server-admin-fallback");body.classList.remove("wp-license-server-admin-js");if(root){root.style.display="none";root.removeAttribute("data-mounted");}if(fallback){fallback.style.display="block";}};var timer=window.setTimeout(function(){var root=document.getElementById("wp-license-server-admin-root");if(root&&root.getAttribute("data-mounted")==="true"){return;}restore();},8000);window.addEventListener("wp-license-server-admin-mounted",function(){window.clearTimeout(timer);},{once:true});}());</script>';
        echo '<div id="wp-license-server-admin-root" style="display:none"><div id="' . esc_attr( self::APP_ROOT_ID ) . '"><div class="wp-license-server-admin-boot"><div class="wp-license-server-admin-boot__panel"><div class="wp-license-server-admin-boot__badge">License Server</div><div class="wp-license-server-admin-boot__title"></div><div class="wp-license-server-admin-boot__line wp-license-server-admin-boot__line--wide"></div><div class="wp-license-server-admin-boot__line"></div><div class="wp-license-server-admin-boot__grid"><span class="wp-license-server-admin-boot__metric"></span><span class="wp-license-server-admin-boot__metric"></span><span class="wp-license-server-admin-boot__metric"></span></div></div></div></div></div>';

        // PHP fallback: visible until the React app mounts successfully.
        echo '<div id="wp-license-server-admin-fallback" class="wrap">';
        echo '<h1>' . esc_html( 'License Server' ) . '</h1>';
        $this->render_notices();
        $this->render_status_tabs();
        echo '<p class="description">' . esc_html__( 'JavaScript is disabled or the admin app failed to load.', 'wp-license-server' ) . '</p>';
        $this->render_create_form();

        $list_table = new LicenseListTable( $this->license_repo, $this->activation_repo );
        $list_table->prepare_items();
        echo '<form method="post">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '" />';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        echo '<input type="hidden" name="status" value="' . esc_attr( isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '' ) . '" />';
        wp_nonce_field( 'bulk-licenses' );
        $list_table->display();
        echo '</form>';
        echo '</div>';
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== $this->hook_suffix ) {
            return;
        }

        $this->enqueue_shell_styles();
        wp_enqueue_media();

        // Keep the page full-height and neutralize the remaining wp-admin layout
        // chrome around the React mount without breaking the hidden PHP fallback.
        $reset_css = '
            body.tools_page_wp-license-server,
            body.tools_page_wp-license-server #wpwrap,
            body.tools_page_wp-license-server #wpbody,
            body.tools_page_wp-license-server #wpcontent,
            body.tools_page_wp-license-server #wpbody-content,
            body.tools_page_wp-license-server #wp-license-server-admin-root,
            body.tools_page_wp-license-server #' . self::APP_ROOT_ID . ' {
                min-height: 100% !important;
                height: 100% !important;
            }
            body.tools_page_wp-license-server #wpcontent {
                margin-left: 0 !important;
                padding-left: 0 !important;
            }
            body.tools_page_wp-license-server #wpbody-content {
                margin-top: 0 !important;
                padding-top: 0 !important;
                padding-bottom: 0 !important;
            }
             body.tools_page_wp-license-server #wp-license-server-admin-root {
                 display: block;
                 min-height: 100%;
             }
             body.tools_page_wp-license-server.wp-license-server-admin-js #wp-license-server-admin-root {
                 display: block !important;
             }
             body.tools_page_wp-license-server.wp-license-server-admin-js #wp-license-server-admin-fallback {
                 display: none !important;
             }
             body.tools_page_wp-license-server #' . self::APP_ROOT_ID . ' {
                 display: block;
                 min-height: 100%;
                 font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
             }
             body.tools_page_wp-license-server .wp-license-server-admin-boot {
                 min-height: 100vh;
                 padding: 32px;
                 display: flex;
                 align-items: flex-start;
                 justify-content: center;
                 background: #f4f6fa;
             }
             body.tools_page_wp-license-server .wp-license-server-admin-boot__panel {
                 width: min(100%, 1180px);
                 padding: 28px;
                 border: 1px solid rgba(15, 23, 42, 0.08);
                 border-radius: 20px;
                 background: #ffffff;
                 box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
             }
             body.tools_page_wp-license-server .wp-license-server-admin-boot__badge {
                 display: inline-flex;
                 align-items: center;
                 padding: 6px 10px;
                 border-radius: 999px;
                 background: rgba(79, 70, 229, 0.1);
                 color: #4f46e5;
                 font-size: 12px;
                 font-weight: 700;
                 letter-spacing: 0.04em;
                 text-transform: uppercase;
             }
             body.tools_page_wp-license-server .wp-license-server-admin-boot__title,
             body.tools_page_wp-license-server .wp-license-server-admin-boot__line,
             body.tools_page_wp-license-server .wp-license-server-admin-boot__metric {
                 display: block;
                 background: linear-gradient(90deg, #eef2f7 0%, #f8fafc 50%, #eef2f7 100%);
                 background-size: 200% 100%;
                 animation: wp-license-server-admin-boot-shimmer 1.25s linear infinite;
             }
             body.tools_page_wp-license-server .wp-license-server-admin-boot__title {
                 width: min(360px, 80%);
                 height: 32px;
                 margin: 18px 0 16px;
                 border-radius: 12px;
             }
             body.tools_page_wp-license-server .wp-license-server-admin-boot__line {
                 width: min(640px, 100%);
                 height: 14px;
                 margin-bottom: 12px;
                 border-radius: 999px;
             }
             body.tools_page_wp-license-server .wp-license-server-admin-boot__line--wide {
                 width: min(760px, 100%);
             }
             body.tools_page_wp-license-server .wp-license-server-admin-boot__grid {
                 display: grid;
                 grid-template-columns: repeat(3, minmax(0, 1fr));
                 gap: 16px;
                 margin-top: 28px;
             }
             body.tools_page_wp-license-server .wp-license-server-admin-boot__metric {
                 height: 148px;
                 border-radius: 18px;
             }
             @keyframes wp-license-server-admin-boot-shimmer {
                 0% { background-position: 200% 0; }
                 100% { background-position: -200% 0; }
             }
             @media (max-width: 782px) {
                 body.tools_page_wp-license-server .wp-license-server-admin-boot {
                     padding: 18px;
                 }
                 body.tools_page_wp-license-server .wp-license-server-admin-boot__panel {
                     padding: 20px;
                     border-radius: 18px;
                 }
                 body.tools_page_wp-license-server .wp-license-server-admin-boot__grid {
                     grid-template-columns: 1fr;
                 }
             }
             body.tools_page_wp-license-server #wp-license-server-admin-root *,
             body.tools_page_wp-license-server #wp-license-server-admin-root *::before,
             body.tools_page_wp-license-server #wp-license-server-admin-root *::after {
                box-sizing: border-box;
            }
        ';
        wp_add_inline_style( 'wp-admin', $reset_css );

        $admin_js_path = plugin_dir_path( WP_LICENSE_SERVER_FILE ) . 'dist/license-server-admin-app.js';
        wp_register_script(
            'wp-license-server-admin-app',
            plugins_url( 'dist/license-server-admin-app.js', WP_LICENSE_SERVER_FILE ),
            [],
            file_exists( $admin_js_path ) ? (string) filemtime( $admin_js_path ) : WP_LICENSE_SERVER_VERSION,
            true
        );

        $admin_css_path = plugin_dir_path( WP_LICENSE_SERVER_FILE ) . 'dist/license-server-admin-app.css';
        if ( file_exists( $admin_css_path ) ) {
            wp_enqueue_style(
                'wp-license-server-admin-app',
                plugins_url( 'dist/license-server-admin-app.css', WP_LICENSE_SERVER_FILE ),
                array(),
                (string) filemtime( $admin_css_path )
            );
        }

        wp_add_inline_script(
            'wp-license-server-admin-app',
            'window.WpLicenseServerAdmin = ' . wp_json_encode(
                [
                    'restBase'            => esc_url_raw( rest_url( 'license-server/v1/admin' ) ),
                    'nonce'               => wp_create_nonce( 'wp_rest' ),
                    'tiers'               => $this->get_tier_options(),
                    'pageTitle'           => __( 'License Server', 'wp-license-server' ),
                    'status'              => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '',
                    'encryptionKeySource' => EncryptionService::get_key_source(),
                    'developmentMode'     => (bool) get_option( 'wplicense_development_mode', false ),
                ]
            ) . ';',
            'before'
        );

        wp_enqueue_script( 'wp-license-server-admin-app' );
    }

    /**
     * Enqueue the shell stylesheet so shell page classes render consistently
     * inside iframe-based admin screens.
     */
    private function enqueue_shell_styles(): void {
        if ( ! class_exists( 'WP_React_UI_Asset_Loader' ) || ! method_exists( 'WP_React_UI_Asset_Loader', 'get_preload_assets' ) ) {
            return;
        }

        $assets = \WP_React_UI_Asset_Loader::get_preload_assets();
        $css    = isset( $assets['css'] ) && is_array( $assets['css'] ) ? $assets['css'] : array();

        if ( empty( $css ) ) {
            $main_entry_url = \WP_React_UI_Asset_Loader::get_entry_asset_url( 'src/main.tsx' );
            if ( is_string( $main_entry_url ) && false !== strpos( $main_entry_url, '/src/main.tsx' ) ) {
                $css[] = str_replace( '/src/main.tsx', '/src/index.css', $main_entry_url );
            }
        }

        foreach ( array_values( array_unique( $css ) ) as $index => $css_url ) {
            if ( ! is_string( $css_url ) || '' === $css_url ) {
                continue;
            }

            wp_enqueue_style(
                'wp-react-ui-shell-css-' . $index,
                $css_url,
                array(),
                null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
            );
        }

        $outside_url = \WP_React_UI_Asset_Loader::get_entry_asset_url( 'src/outside.css' );
        if ( is_string( $outside_url ) && '' !== $outside_url ) {
            wp_enqueue_style(
                'wp-react-ui-shell-outside',
                $outside_url,
                array(),
                null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
            );
        }
    }

    /**
     * Process single and bulk actions.
     */
    private function process_actions(): void {
        if ( isset( $_POST['wplicense_action'] ) && 'create_license' === sanitize_text_field( wp_unslash( $_POST['wplicense_action'] ) ) ) {
            $this->handle_create_action();
        }

        // Single delete.
        if ( isset( $_GET['action'], $_GET['license_id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'delete' ) {
            $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
            if ( ! wp_verify_nonce( $nonce, 'delete_license_' . absint( $_GET['license_id'] ) ) ) {
                wp_die( esc_html__( 'Security check failed.' ) );
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to do this.' ) );
            }

            $this->license_repo->delete( absint( $_GET['license_id'] ) );

            wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&deleted=1' ) );
            exit;
        }

        // Single deactivate-all.
        if ( isset( $_GET['action'], $_GET['license_id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'deactivate_all' ) {
            $nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
            if ( ! wp_verify_nonce( $nonce, 'deactivate_all_' . absint( $_GET['license_id'] ) ) ) {
                wp_die( esc_html__( 'Security check failed.' ) );
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to do this.' ) );
            }

            $license_id = absint( $_GET['license_id'] );
            $active     = $this->activation_repo->get_all_active( $license_id );
            foreach ( $active as $activation ) {
                $this->activation_repo->deactivate( $license_id, $activation->domain );
            }

            wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&deactivated=1' ) );
            exit;
        }

        // Bulk delete.
        if (
            isset( $_POST['_wpnonce'], $_POST['action'] ) &&
            ( $_POST['action'] === 'delete' || ( isset( $_POST['action2'] ) && $_POST['action2'] === 'delete' ) )
        ) {
            $nonce = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
            if ( ! wp_verify_nonce( $nonce, 'bulk-licenses' ) ) {
                wp_die( esc_html__( 'Security check failed.' ) );
            }

            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'You do not have permission to do this.' ) );
            }

            $ids = isset( $_POST['license_ids'] ) ? array_map( 'absint', (array) $_POST['license_ids'] ) : [];
            foreach ( $ids as $id ) {
                $this->license_repo->delete( $id );
            }

            wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&deleted=' . count( $ids ) ) );
            exit;
        }
    }

    private function handle_create_action(): void {
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'create_license' ) ) {
            wp_die( esc_html__( 'Security check failed.' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.' ) );
        }

        $this->submitted_create_values = [
            'customer_email'   => isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '',
            'customer_name'    => isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '',
            'role'             => isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : 'customer',
            'tier'             => isset( $_POST['tier'] ) ? sanitize_text_field( wp_unslash( $_POST['tier'] ) ) : 'pro',
            'valid_until'      => isset( $_POST['valid_until'] ) ? sanitize_text_field( wp_unslash( $_POST['valid_until'] ) ) : '',
            'payment_interval' => isset( $_POST['payment_interval'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_interval'] ) ) : 'yearly',
            'notes'            => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
        ];

        $result = $this->license_service->create(
            [
                'customer_name'    => $this->submitted_create_values['customer_name'],
                'customer_email'   => $this->submitted_create_values['customer_email'],
                'role'             => $this->submitted_create_values['role'],
                'tier'             => $this->submitted_create_values['tier'],
                'valid_until'      => $this->submitted_create_values['valid_until'],
                'payment_interval' => $this->submitted_create_values['payment_interval'],
                'notes'            => $this->submitted_create_values['notes'],
            ]
        );

        if ( is_wp_error( $result ) ) {
            $this->create_error = $result;
            return;
        }

        $this->created_license_key                 = $result->license_key;
        $this->submitted_create_values['customer_email'] = '';
        $this->submitted_create_values['customer_name']  = '';
        $this->submitted_create_values['role']           = 'customer';
        $this->submitted_create_values['valid_until']    = '';
        $this->submitted_create_values['notes']          = '';
    }

    /**
     * Render status filter tabs.
     */
    private function render_status_tabs(): void {
        $statuses = [ '' => 'All', 'active' => 'Active', 'expired' => 'Expired', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled' ];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

        echo '<ul class="subsubsub">';
        $links = [];
        foreach ( $statuses as $value => $label ) {
            $url   = admin_url( 'tools.php?page=' . self::MENU_SLUG . ( $value ? '&status=' . $value : '' ) );
            $class = $current === $value ? ' class="current"' : '';
            $links[] = '<li><a href="' . esc_url( $url ) . '"' . $class . '>' . esc_html( $label ) . '</a></li>';
        }
        echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link is escaped.
        echo '</ul>';
        echo '<br class="clear" />';
    }

    private function render_notices(): void {
        if ( $this->created_license_key ) {
            printf(
                '<div class="notice notice-success"><p><strong>%s</strong></p><p class="wp-license-server-admin-license-key">%s <code>%s</code></p></div>',
                esc_html__( 'License created. Copy the full key now — only the prefix is stored in list views.', 'wp-license-server' ),
                esc_html__( 'Full key:', 'wp-license-server' ),
                esc_html( $this->created_license_key )
            );
        }

        if ( $this->create_error instanceof \WP_Error ) {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                esc_html__( 'Could not create license.', 'wp-license-server' ),
                esc_html( $this->create_error->get_error_message() )
            );
        }

        if ( isset( $_GET['deleted'] ) ) {
            $deleted = absint( wp_unslash( $_GET['deleted'] ) );
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(
                    1 === $deleted
                        ? __( 'License deleted.', 'wp-license-server' )
                        : sprintf(
                            /* translators: %d: deleted license count */
                            __( '%d licenses deleted.', 'wp-license-server' ),
                            $deleted
                        )
                )
            );
        }

        if ( isset( $_GET['deactivated'] ) ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__( 'All activations were deactivated for that license.', 'wp-license-server' )
            );
        }
    }

    private function render_create_form(): void {
        ?>
        <div class="wp-license-server-admin-card">
            <h2><?php esc_html_e( 'Create License', 'wp-license-server' ); ?></h2>
            <form method="post">
                <?php wp_nonce_field( 'create_license' ); ?>
                <input type="hidden" name="wplicense_action" value="create_license" />
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="wplicense-customer-email"><?php esc_html_e( 'Customer Email', 'wp-license-server' ); ?></label></th>
                            <td><input id="wplicense-customer-email" class="regular-text" type="email" name="customer_email" required value="<?php echo esc_attr( $this->submitted_create_values['customer_email'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wplicense-customer-name"><?php esc_html_e( 'Customer Name', 'wp-license-server' ); ?></label></th>
                            <td><input id="wplicense-customer-name" class="regular-text" type="text" name="customer_name" value="<?php echo esc_attr( $this->submitted_create_values['customer_name'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wplicense-tier"><?php esc_html_e( 'Tier', 'wp-license-server' ); ?></label></th>
                            <td>
                                <select id="wplicense-tier" name="tier">
                                    <?php foreach ( $this->get_tier_options() as $tier ) : ?>
                                        <option value="<?php echo esc_attr( $tier['value'] ); ?>" <?php selected( $this->submitted_create_values['tier'], $tier['value'] ); ?>>
                                            <?php echo esc_html( $tier['label'] . ' (' . $tier['maxActivations'] . ')' ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wplicense-role"><?php esc_html_e( 'Role', 'wp-license-server' ); ?></label></th>
                            <td>
                                <select id="wplicense-role" name="role">
                                    <option value="customer" <?php selected( $this->submitted_create_values['role'], 'customer' ); ?>><?php esc_html_e( 'Customer', 'wp-license-server' ); ?></option>
                                    <option value="owner" <?php selected( $this->submitted_create_values['role'], 'owner' ); ?>><?php esc_html_e( 'Owner', 'wp-license-server' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wplicense-valid-until"><?php esc_html_e( 'Valid Until', 'wp-license-server' ); ?></label></th>
                            <td><input id="wplicense-valid-until" type="date" name="valid_until" required value="<?php echo esc_attr( $this->submitted_create_values['valid_until'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wplicense-payment-interval"><?php esc_html_e( 'Payment Interval', 'wp-license-server' ); ?></label></th>
                            <td>
                                <select id="wplicense-payment-interval" name="payment_interval">
                                    <option value="monthly" <?php selected( $this->submitted_create_values['payment_interval'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'wp-license-server' ); ?></option>
                                    <option value="yearly" <?php selected( $this->submitted_create_values['payment_interval'], 'yearly' ); ?>><?php esc_html_e( 'Yearly', 'wp-license-server' ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="wplicense-notes"><?php esc_html_e( 'Notes', 'wp-license-server' ); ?></label></th>
                            <td><textarea id="wplicense-notes" name="notes" class="large-text" rows="4"><?php echo esc_textarea( $this->submitted_create_values['notes'] ); ?></textarea></td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button( __( 'Create License', 'wp-license-server' ) ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * @return array{value: string, label: string, maxActivations: int, features: array<int, string>}[]
     */
    private function get_tier_options(): array {
        $tiers = [];

        foreach ( TierConfig::TIERS as $key => $config ) {
            $tiers[] = [
                'value'          => $key,
                'label'          => $config['label'],
                'maxActivations' => (int) $config['max_activations'],
                'features'       => $config['features'],
            ];
        }

        return $tiers;
    }
}
