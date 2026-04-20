<?php
/**
 * Tests for plugin bootstrap wiring.
 *
 * @package WpLicenseServer\Tests
 */

declare(strict_types=1);

namespace WpLicenseServer\Tests;

use WpLicenseServer\Plugin;

final class PluginTest extends \WP_UnitTestCase {

    public function tear_down(): void {
        wp_clear_scheduled_hook( 'wplicense_check_expiry' );
        parent::tear_down();
    }

    public function test_init_registers_hooks_without_admin_page_constructor_errors(): void {
        set_current_screen( 'dashboard' );

        ( new Plugin() )->init();

        $this->assertNotFalse( has_action( 'rest_api_init' ) );
        $this->assertNotFalse( has_action( 'admin_menu' ) );
        $this->assertNotFalse( wp_next_scheduled( 'wplicense_check_expiry' ) );
    }

    public function test_init_registers_chat_archive_and_delete_routes(): void {
        ( new Plugin() )->init();

        do_action( 'rest_api_init' );
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey( '/license-server/v1/chat/archive', $routes );
        $this->assertArrayHasKey( '/license-server/v1/chat/delete', $routes );
        $this->assertArrayHasKey( '/license-server/v1/chat/unarchive', $routes );
    }
}
