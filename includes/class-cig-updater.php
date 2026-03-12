<?php
/**
 * GitHub Release Auto-Updater
 *
 * Checks https://api.github.com/repos/{owner}/{repo}/releases/latest
 * and injects update data into WordPress when a newer tag is found.
 * Response is cached for 12 hours via a WP transient.
 *
 * Usage (from main plugin file):
 *   new CIG_Updater( __FILE__, 'Samsiani', 'gn-industrial-custom-invoice-generator', CIG_VERSION );
 *
 * @package CIG
 * @since 4.9.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CIG_Updater {

    private $plugin_file;
    private $plugin_slug;
    private $github_owner;
    private $github_repo;
    private $current_version;
    private $transient_key = 'cig_ind_updater_response';
    private $cache_ttl = 43200; // 12 hours

    public function __construct( $plugin_file, $github_owner, $github_repo, $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename( $plugin_file );
        $this->github_owner    = $github_owner;
        $this->github_repo     = $github_repo;
        $this->current_version = $current_version;

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',                          [ $this, 'plugin_info'       ], 10, 3 );
        add_action( 'upgrader_process_complete',            [ $this, 'purge_transient'   ], 10, 2 );
        add_filter( 'upgrader_source_selection',            [ $this, 'fix_source_dir'    ], 10, 4 );
    }

    private function get_latest_release() {
        $cached = get_transient( $this->transient_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $api_url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode( $this->github_owner ),
            rawurlencode( $this->github_repo )
        );

        $response = wp_remote_get( $api_url, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            'headers'    => [ 'Accept' => 'application/vnd.github+json' ],
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            set_transient( $this->transient_key, false, 300 );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
            set_transient( $this->transient_key, false, 300 );
            return false;
        }

        $package_url = '';
        if ( ! empty( $data['assets'] ) ) {
            foreach ( $data['assets'] as $asset ) {
                if ( ! empty( $asset['browser_download_url'] )
                    && substr( $asset['browser_download_url'], -4 ) === '.zip' ) {
                    $package_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $release = [
            'version'     => ltrim( $data['tag_name'], 'v' ),
            'package_url' => $package_url,
            'body'        => $data['body'] ?? '',
            'published'   => $data['published_at'] ?? '',
        ];

        set_transient( $this->transient_key, $release, $this->cache_ttl );
        return $release;
    }

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        if ( version_compare( $release['version'], $this->current_version, '>' ) ) {
            $update              = new stdClass();
            $update->id          = $this->github_repo;
            $update->slug        = dirname( $this->plugin_slug );
            $update->plugin      = $this->plugin_slug;
            $update->new_version = $release['version'];
            $update->url         = 'https://github.com/' . $this->github_owner . '/' . $this->github_repo;
            $update->package     = $release['package_url'];
            $update->icons       = [];
            $update->banners     = [];
            $update->tested      = get_bloginfo( 'version' );
            $update->requires_php = '7.4';
            $update->compatibility = new stdClass();

            $transient->response[ $this->plugin_slug ] = $update;
        } else {
            unset( $transient->response[ $this->plugin_slug ] );
        }

        return $transient;
    }

    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( empty( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
            return $result;
        }

        $release     = $this->get_latest_release();
        $plugin_data = get_plugin_data( $this->plugin_file );

        $info                = new stdClass();
        $info->name          = $plugin_data['Name'];
        $info->slug          = dirname( $this->plugin_slug );
        $info->version       = $release ? $release['version'] : $this->current_version;
        $info->author        = $plugin_data['Author'];
        $info->homepage      = 'https://github.com/' . $this->github_owner . '/' . $this->github_repo;
        $info->requires      = '5.8';
        $info->requires_php  = '7.4';
        $info->tested        = get_bloginfo( 'version' );
        $info->last_updated  = $release ? $release['published'] : '';
        $info->download_link = $release ? $release['package_url'] : '';
        $info->sections      = [
            'description' => $plugin_data['Description'],
            'changelog'   => ( $release && ! empty( $release['body'] ) )
                ? nl2br( esc_html( $release['body'] ) )
                : 'See <a href="https://github.com/' . esc_attr( $this->github_owner ) . '/' . esc_attr( $this->github_repo ) . '/releases" target="_blank">GitHub Releases</a> for the full changelog.',
        ];

        return $info;
    }

    /**
     * Rename extracted folder to match installed plugin folder name.
     * GitHub ZIPs extract as "repo-name/" but WP expects the installed folder name.
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $source;
        }

        $expected_dir = trailingslashit( $remote_source ) . dirname( $this->plugin_slug ) . '/';

        if ( $source !== $expected_dir ) {
            global $wp_filesystem;
            if ( $wp_filesystem->move( $source, $expected_dir ) ) {
                return $expected_dir;
            }
        }

        return $source;
    }

    public function purge_transient( $upgrader, $hook_extra ) {
        if ( empty( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
            return;
        }

        $updated = [];
        if ( ! empty( $hook_extra['plugins'] ) ) {
            $updated = (array) $hook_extra['plugins'];
        } elseif ( ! empty( $hook_extra['plugin'] ) ) {
            $updated = [ $hook_extra['plugin'] ];
        }

        if ( in_array( $this->plugin_slug, $updated, true ) ) {
            delete_transient( $this->transient_key );
        }
    }
}
