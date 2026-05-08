<?php
/**
 * WP_List_Table for licenses in the admin UI.
 *
 * Shows key prefix (never full key), customer info, tier, status,
 * activation count, and row actions.
 *
 * @package WpLicenseServer\Admin
 */

declare(strict_types=1);

namespace WpLicenseServer\Admin;

use WpLicenseServer\Repositories\ActivationRepository;
use WpLicenseServer\Repositories\LicenseRepository;

if ( ! class_exists( '\WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class LicenseListTable extends \WP_List_Table {

    public function __construct(
        private readonly LicenseRepository $license_repo,
        private readonly ActivationRepository $activation_repo,
    ) {
        parent::__construct( [
            'singular' => 'license',
            'plural'   => 'licenses',
            'ajax'     => false,
        ] );
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array {
        return [
            'cb'          => '<input type="checkbox" />',
            'id'          => __( 'ID', 'wp-license-server' ),
            'key_prefix'  => __( 'Key Prefix', 'wp-license-server' ),
            'customer_name'  => __( 'Customer', 'wp-license-server' ),
            'customer_email' => __( 'Email', 'wp-license-server' ),
            'tier'        => __( 'Tier', 'wp-license-server' ),
            'status'      => __( 'Status', 'wp-license-server' ),
            'activations' => __( 'Activations', 'wp-license-server' ),
            'valid_until' => __( 'Valid Until', 'wp-license-server' ),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public function get_sortable_columns(): array {
        return [
            'tier'        => [ 'tier', false ],
            'status'      => [ 'status', false ],
            'valid_until' => [ 'valid_until', true ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function get_bulk_actions(): array {
        return [ 'delete' => __( 'Delete', 'wp-license-server' ) ];
    }

    public function prepare_items(): void {
        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = [ $columns, $hidden, $sortable ];

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : null;

        $all_items = $this->license_repo->find_all( $status );

        $per_page    = 20;
        $total_items = count( $all_items );
        $current_page = $this->get_pagenum();

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total_items / $per_page ),
        ] );

        $this->items = array_slice( $all_items, ( $current_page - 1 ) * $per_page, $per_page );
    }

    /**
     * Checkbox column for bulk actions.
     *
     * @param \WpLicenseServer\Models\License $item
     */
    public function column_cb( $item ): string {
        return '<input type="checkbox" name="license_ids[]" value="' . esc_attr( (string) $item->id ) . '" />';
    }

    /**
     * Default column renderer — escapes all output.
     *
     * @param \WpLicenseServer\Models\License $item
     */
    public function column_default( $item, $column_name ): string {
        return match ( $column_name ) {
            'id', 'customer_name', 'customer_email', 'tier', 'valid_until'
                => esc_html( (string) $item->$column_name ),
            default => '',
        };
    }

    /**
     * Key prefix column — NEVER shows full key.
     *
     * @param \WpLicenseServer\Models\License $item
     */
    public function column_key_prefix( $item ): string {
        return '<code>' . esc_html( $item->key_prefix ) . '…</code>';
    }

    /**
     * Status column with colored label.
     *
     * @param \WpLicenseServer\Models\License $item
     */
    public function column_status( $item ): string {
        $colors = [
            'active'    => '#00a32a',
            'expired'   => '#996800',
            'suspended' => '#d63638',
            'cancelled' => '#787c82',
        ];
        $labels = [
            'active'    => esc_html__( 'Active', 'wp-license-server' ),
            'expired'   => esc_html__( 'Expired', 'wp-license-server' ),
            'suspended' => esc_html__( 'Suspended', 'wp-license-server' ),
            'cancelled' => esc_html__( 'Cancelled', 'wp-license-server' ),
        ];
        $color  = $colors[ $item->status ] ?? '#787c82';
        $label  = $labels[ $item->status ] ?? esc_html__( 'Unknown', 'wp-license-server' );
        return '<span style="color:' . esc_attr( $color ) . '; font-weight:600;">'
            . $label
            . '</span>';
    }

    /**
     * Activations column — "current / max".
     *
     * @param \WpLicenseServer\Models\License $item
     */
    public function column_activations( $item ): string {
        $current = $this->activation_repo->count_active( $item->id );
        return esc_html( $current . ' / ' . $item->max_activations );
    }

    /**
     * Row actions: Deactivate All, Delete.
     *
     * @param \WpLicenseServer\Models\License $item
     */
    protected function handle_row_actions( $item, $column_name, $primary ): string {
        if ( $column_name !== $primary ) {
            return '';
        }

        $actions = [];

        $deactivate_url = wp_nonce_url(
            admin_url( 'tools.php?page=wp-license-server&action=deactivate_all&license_id=' . $item->id ),
            'deactivate_all_' . $item->id
        );
        $actions['deactivate_all'] = '<a href="' . esc_url( $deactivate_url ) . '">' . esc_html__( 'Deactivate All', 'wp-license-server' ) . '</a>';

        $delete_url = wp_nonce_url(
            admin_url( 'tools.php?page=wp-license-server&action=delete&license_id=' . $item->id ),
            'delete_license_' . $item->id
        );
        $actions['delete'] = '<a href="' . esc_url( $delete_url ) . '" class="delete" onclick="return confirm(\'' . esc_js( __( 'Delete this license?', 'wp-license-server' ) ) . '\');">' . esc_html__( 'Delete', 'wp-license-server' ) . '</a>';

        return $this->row_actions( $actions );
    }
}
