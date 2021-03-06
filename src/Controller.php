<?php

namespace WP2Static;

use ZipArchive;
use WP_Error;
use Exception;
use WP_CLI;

class Controller {
    const VERSION = '7.0-build0001';
    const OPTIONS_KEY = 'wp2static-options';
    const HOOK = 'wp2static';

    /**
     * Main controller of WP2Static
     *
     * @var \WP2Static\Controller Instance.
     */
    protected static $instance = null;

    protected function __construct() {}

    /**
     * Returns instance of WP2Static Controller
     *
     * @return \WP2Static\Controller Instance of self.
     */
    public static function getInstance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->options = new Options(
                self::OPTIONS_KEY
            );
        }

        return self::$instance;
    }

    public static function init( $bootstrap_file ) {
        $instance = self::getInstance();

        register_activation_hook(
            $bootstrap_file,
            array( $instance, 'activate' )
        );

        if ( is_admin() ) {
            add_action(
                'admin_menu',
                array(
                    $instance,
                    'registerOptionsPage',
                )
            );
            add_filter( 'custom_menu_order', '__return_true' );
            add_filter( 'menu_order', array( $instance, 'set_menu_order' ) );
        }

        $instance->settings = $instance->options->getSettings( true );
        $instance->site_url = SiteInfo::getUrl( 'site' );

        if ( ! is_string( $instance->site_url ) ) {
            $err = 'Site URL not defined ';
            WsLog::l( $err );
            throw new Exception( $err );
        }

        // create DB table for crawl caching
        CrawlCache::createTable();

        // capture URL hosts for use in detecting internal links
        $instance->site_url_host =
            parse_url( $instance->site_url, PHP_URL_HOST );

        $instance->destination_url = $instance->settings['baseUrl'];

        if ( ! is_string( $instance->destination_url ) ) {
            $err = 'Destination URL not defined';
            WsLog::l( $err );
            throw new Exception( $err );
        }

        $instance->loadRewriteRules();

        add_action(
            'wp2static_headless_hook',
            [ 'WP2Static\Controller', 'wp2static_headless' ],
            10,
            0
        );

        /*
         * Register actions for when we should invalidate cache for
         * a URL(s) or whole site
         *
         */
        $single_url_invalidation_events = [
            'save_post',
            'deleted_post',
        ];

        $full_site_invalidation_events = [
            'switch_theme',
        ];

        foreach ( $single_url_invalidation_events as $invalidation_events ) {
            add_action(
                $invalidation_events,
                [ 'WP2Static\Controller', 'invalidate_single_url_cache' ],
                0
            );
        }

        if ( isset( $instance->settings['redeployOnPostUpdates'] ) ) {
            add_action(
                'save_post',
                [ 'WP2Static\Controller', 'wp2static_headless' ],
                0
            );
        }

        if ( isset( $instance->settings['displayDashboardWidget'] ) ) {
            add_action(
                'wp_dashboard_setup',
                [ 'WP2Static\Controller', 'wp2static_add_dashboard_widgets' ],
                0
            );
        }

        add_action(
            'admin_enqueue_scripts',
            [ 'WP2Static\Controller', 'load_wp2static_admin_js' ]
        );

        return $instance;
    }

    public function set_menu_order( $menu_order ) {
        $order = array();
        $file  = plugin_basename( __FILE__ );
        foreach ( $menu_order as $index => $item ) {
            if ( $item === 'index.php' ) {
                $order[] = $item;
            }
        }

        $order = array(
            'index.php',
            'wp2static',
        );

        return $order;
    }


    public function setDefaultOptions() {
        if ( null === $this->options->getOption( 'version' ) ) {
            $this->options
            ->setOption( 'version', self::VERSION )
            ->setOption( 'static_export_settings', self::VERSION )
            // set default options
            ->setOption( 'rewriteWPPaths', '1' )
            ->setOption( 'removeConditionalHeadComments', '1' )
            ->setOption( 'removeWPMeta', '1' )
            ->setOption( 'removeWPLinks', '1' )
            ->setOption( 'removeHTMLComments', '1' )
            ->setOption( 'parse_css', '1' )
            ->save();
        }
    }

    public function activate_for_single_site() {
        $this->setDefaultOptions();
    }

    public function activate( $network_wide ) {
        if ( $network_wide ) {
            global $wpdb;

            $query = 'SELECT blog_id FROM %s WHERE site_id = %d;';

            $site_ids = $wpdb->get_col(
                sprintf(
                    $query,
                    $wpdb->blogs,
                    $wpdb->siteid
                )
            );

            foreach ( $site_ids as $site_id ) {
                switch_to_blog( $site_id );
                $this->activate_for_single_site();
            }

            restore_current_blog();
        } else {
            $this->activate_for_single_site();
        }
    }

    public function registerOptionsPage() {
        $plugins_url = plugin_dir_url( dirname( __FILE__ ) );
        $page = add_menu_page(
            __( 'WP2Static', 'static-html-output-plugin' ),
            __( 'WP2Static', 'static-html-output-plugin' ),
            'manage_options',
            self::HOOK,
            array( self::$instance, 'renderOptionsPage' ),
            'dashicons-shield-alt'
        );

        add_action(
            'admin_print_styles-' . $page,
            array(
                $this,
                'enqueueAdminStyles',
            )
        );
    }

    public function enqueueAdminStyles() {
        $plugins_url = plugin_dir_url( dirname( __FILE__ ) );

        wp_enqueue_style(
            self::HOOK . '-admin',
            $plugins_url . 'wp2static.css?cache-buster=wp2static',
            array(),
            $this::VERSION
        );
    }

    // NOTE: wrapper for UI to echo success response
    public function finalize_deployment() {
        $deployer = new Deployer();
        $deployer->finalizeDeployment();

        echo 'SUCCESS';
    }

    public function download_export_log() {
        $export_log = SiteInfo::getPath( 'uploads' ) .
            'wp2static-working-files/EXPORT-LOG.txt';

        if ( is_file( $export_log ) ) {
            // create zip of export log in tmp file
            $export_log_zip = SiteInfo::getPath( 'uploads' ) .
                'wp2static-working-files/EXPORT-LOG.zip';

            $zip_archive = new ZipArchive();
            $zip_opened =
                $zip_archive->open( $export_log_zip, ZipArchive::CREATE );

            if ( $zip_opened !== true ) {
                return new WP_Error( 'Could not create archive' );
            }

            $real_filepath = realpath( $export_log );

            if ( ! $real_filepath ) {
                $err = 'Trying to add unknown file to Zip: ' . $export_log;
                WsLog::l( $err );
                throw new Exception( $err );
            }

            if ( ! $zip_archive->addFile(
                $real_filepath,
                'EXPORT-LOG.txt'
            )
            ) {
                return new WP_Error( 'Could not add Export Log to zip' );
            }

            $zip_archive->close();

            echo SiteInfo::getUrl( 'uploads' ) .
                'wp2static-working-files/EXPORT-LOG.zip';
        } else {
            // serve 500 response to client
            throw new Exception( 'Unable to find Export Log to create ZIP' );
        }
    }

    public function loadRewriteRules() {
        // get user rewrite rules, use regular and escaped versions of them
        $this->rewrite_rules =
            RewriteRules::generate(
                $this->site_url,
                $this->destination_url
            );

        if ( ! $this->rewrite_rules ) {
            $err = 'No URL rewrite rules defined';
            WsLog::l( $err );
            throw new Exception( $err );
        }
    }

    public function crawl_site() {
        $site_crawler = new SiteCrawler(
            $this->rewrite_rules,
            $this->site_url_host,
            $this->destination_url
        );

        $site_crawler->crawl();
    }

    public function test_folder() {
        $archive_processor = new ArchiveProcessor();

        $target_folder = $this->settings['targetFolder'];

        $has_safety_file =
            $archive_processor->dir_has_safety_file( $target_folder );
        $is_empty =
            $archive_processor->dir_is_empty( $target_folder );

        if ( $has_safety_file || $is_empty ) {
            wp_die( 'SUCCESS', '', 200 );
        }

        wp_die(
            'Not permitted to write to target directory',
            '',
            500
        );
    }

    public function generate_filelist_preview() {
        $plugin_hook = 'wp2static';

        $initial_file_list_count =
            FilesHelper::buildInitialFileList(
                true,
                SiteInfo::getPath( 'uploads' ),
                $this->settings
            );

        if ( $initial_file_list_count < 1 ) {
            $err = 'Initial file list unable to be generated';
            http_response_code( 500 );
            echo $err;
            WsLog::l( $err );
            throw new Exception( $err );
        }

        $via_ui = filter_input( INPUT_POST, 'ajax_action' );

        if ( is_string( $via_ui ) ) {
            echo $initial_file_list_count;
        }
    }

    public function delete_crawl_cache() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wp2static_crawl_cache';

        $wpdb->query( "TRUNCATE TABLE $table_name" );

        $sql =
            "SELECT count(*) FROM $table_name";

        $count = $wpdb->get_var( $sql );

        if ( $count === '0' ) {
            http_response_code( 200 );

            echo 'SUCCESS';
        } else {
            http_response_code( 500 );
        }
    }

    public function load_wp2static_admin_js( $hook ) {
        if ( $hook !== 'toplevel_page_wp2static' ) {
            return;
        }

        $plugin = self::getInstance();

        wp_register_script(
            'wp2static_admin_js',
            SiteInfo::getUrl( 'plugins' ) .
                'static-html-output-plugin/' . // TODO: rm hardcoding slug
                'views/wp2static-admin.js',
            array( 'jquery' ),
            $plugin->version,
            false
        );

        $options = $plugin->options;

        $site_info = json_encode(
            SiteInfo::getAllInfo(),
            JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES
        );

        $current_deployment_method =
            $plugin->options->selected_deployment_option ?
            $plugin->options->selected_deployment_option :
            'folder';

        $data = array(
            'some_string' => __( 'Some string to translate', 'plugin-domain' ),
            'options' => $plugin->options,
            'site_info' => $site_info,
            'onceAction' => self::HOOK . '-options',
            '' => self::HOOK . '-options',
            'current_deployment_method' => $current_deployment_method,
        );

        wp_localize_script( 'wp2static_admin_js', 'wp2staticString', $data );
        wp_enqueue_script( 'wp2static_admin_js' );
    }

    public function renderOptionsPage() {
        $view = [];
        $view['options'] = $this->options;
        $view['site_info'] = SiteInfo::getAllInfo();
        $view['onceAction'] = self::HOOK . '-options';

        // TODO: check which are only needed in JS and rm from func
        $view['uploads_writable'] = SiteInfo::isUploadsWritable();
        $view['curl_supported'] = SiteInfo::hasCURLSupport();
        $view['permalinks_defined'] = SiteInfo::permalinksAreDefined();

        require_once WP2STATIC_PATH . 'views/options-page.php';
    }

    public function userIsAllowed() {
        if ( defined( 'WP_CLI' ) ) {
            return true;
        }

        $referred_by_admin = check_admin_referer( self::HOOK . '-options' );
        $user_can_manage_options = current_user_can( 'manage_options' );

        return $referred_by_admin && $user_can_manage_options;
    }

    public function save_options() {
        $via_ui = filter_input( INPUT_POST, 'ajax_action' );

        // Note when running via UI, we save all options
        if ( is_string( $via_ui ) ) {
            if ( ! $this->userIsAllowed() ) {
                exit( 'Not allowed to change plugin options.' );
            }

            $this->options->saveAllOptions();
        }
    }

    public function prepare_for_export() {
        $this->save_options();

        $this->exporter = new Exporter();

        $this->exporter->pre_export_cleanup();

        // TODO: kill this / make UI/CLI option to delete export dir
        // $this->exporter->cleanup_leftover_archives();

        $archive = new Archive();
        $archive->create();

        $this->logEnvironmentalInfo();

        $this->exporter->generateModifiedFileList();

        $via_ui = filter_input( INPUT_POST, 'ajax_action' );

        if ( is_string( $via_ui ) ) {
            echo 'SUCCESS';
        }
    }

    public function reset_default_settings() {
        if ( ! delete_option( 'wp2static-options' ) ) {
            $err = 'Couldn\'t reset plugin to default settings';
            WsLog::l( $err );
            throw new Exception( $err );
        }

        $this->options = new Options( self::OPTIONS_KEY );
        $this->setDefaultOptions();

        echo 'SUCCESS';
    }

    public function post_process_archive_dir() {
        $processor = new ArchiveProcessor();

        $processor->createNetlifySpecialFiles();
        // NOTE: renameWP Directories also doing same server publish
        $processor->renameArchiveDirectories();
        $processor->removeWPCruft();
        $processor->copyStaticSiteToPublicFolder();
        $processor->create_zip();

        $via_ui = filter_input( INPUT_POST, 'ajax_action' );

        if ( is_string( $via_ui ) ) {
            echo 'SUCCESS';
        }
    }

    public function delete_deploy_cache() {
        $working_dir = SiteInfo::getPath( 'uploads' ) .
            'wp2static-working-files';
        $hash_files = glob( "{$working_dir}/*PREVIOUS-HASHES*.txt" );

        if ( ! $hash_files ) {
            echo 'SUCCESS';
            return;
        }

        array_map( 'unlink', $hash_files );

        $via_ui = filter_input( INPUT_POST, 'ajax_action' );

        if ( is_string( $via_ui ) ) {
            echo 'SUCCESS';
        }
    }

    public function logEnvironmentalInfo() {
        $info = array(
            'EXPORT START: ' . date( 'Y-m-d h:i:s' ),
            'PLUGIN VERSION: ' . $this::VERSION,
            'PHP VERSION: ' . phpversion(),
            'OS VERSION: ' . php_uname(),
            'WP VERSION: ' . get_bloginfo( 'version' ),
            'WP URL: ' . get_bloginfo( 'url' ),
            'WP SITEURL: ' . get_option( 'siteurl' ),
            'WP HOME: ' . get_option( 'home' ),
            'WP ADDRESS: ' . get_bloginfo( 'wpurl' ),
            defined( 'WP_CLI' ) ? 'WP-CLI: YES' : 'WP-CLI: NO',
            'STATIC EXPORT URL: ' . $this->exporter->settings['baseUrl'],
            'PERMALINK STRUCTURE: ' . get_option( 'permalink_structure' ),
        );

        if ( isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
            $info[] = 'SERVER SOFTWARE: ' . $_SERVER['SERVER_SOFTWARE'] .
            PHP_EOL;
        }

        $environmental_info = '';
        $environmental_info .= implode( PHP_EOL, $info );
        $environmental_info .= 'ACTIVE PLUGINS: ' . PHP_EOL;

        $active_plugins = get_option( 'active_plugins' );

        foreach ( $active_plugins as $active_plugin ) {
            $environmental_info .= $active_plugin . PHP_EOL;
        }

        $environmental_info .= 'ACTIVE THEME: ';

        $theme = wp_get_theme();

        $environmental_info .= $theme->get( 'Name' ) . ' is version ' .
            $theme->get( 'Version' ) . PHP_EOL;

        $environmental_info .= 'WP2STATIC OPTIONS: ' . PHP_EOL;

        $options = $this->options->getAllOptions( false );

        foreach ( $options as $key => $value ) {
            $environmental_info .=
                "{$value['Option name']}: {$value['Value']}" . PHP_EOL;
        }

        $environmental_info .= 'SITE URL PATTERNS: ' .
            implode( ',', $this->rewrite_rules['site_url_patterns'] ) .
             PHP_EOL . 'DESTINATION URL PATTERNS: ' .
            implode( ',', $this->rewrite_rules['destination_url_patterns'] );

        $extensions = get_loaded_extensions();

        $environmental_info .= PHP_EOL . 'INSTALLED EXTENSIONS: ' .
            join( ', ', $extensions );

        WsLog::l( $environmental_info );
    }

    public function wp2static_headless() {
        $start_time = microtime();

        $plugin = self::getInstance();
        $plugin->generate_filelist_preview();
        $plugin->prepare_for_export();
        $plugin->crawl_site();
        $plugin->post_process_archive_dir();

        $end_time = microtime();

        $duration = $plugin->microtime_diff( $start_time, $end_time );

        WsLog::l( "Generated static site archive in $duration seconds" );

        $deployer = new Deployer();
        $deployer->deploy();

        return null;
    }

    public function microtime_diff( $start, $end = null ) {
        if ( ! $end ) {
            $end = microtime();
        }

        list( $start_usec, $start_sec ) = explode( ' ', $start );
        list( $end_usec, $end_sec ) = explode( ' ', $end );

        $diff_sec = intval( $end_sec ) - intval( $start_sec );
        $diff_usec = floatval( $end_usec ) - floatval( $start_usec );

        return floatval( $diff_sec ) + $diff_usec;
    }

    public function invalidate_single_url_cache( $post_id = 0, $post = null ) {
        $permalink = get_permalink(
            $post->ID
        );

        $site_url = SiteInfo::getUrl( 'site' );

        if ( ! is_string( $permalink ) || ! is_string( $site_url ) ) {
            return;
        }

        $url = str_replace(
            $site_url,
            '/',
            $permalink
        );

        CrawlCache::rmUrl( $url );
    }

    public function wp2static_add_dashboard_widgets() {
        wp_add_dashboard_widget(
            'wp2static__dashboard_widget',
            'WP2Static',
            [ 'WP2Static\Controller', 'wp2static_dashboard_widget_function' ]
        );
    }

    public function wp2static_dashboard_widget_function() {
        echo '<p>Publish whole site as static HTML</p>';
        echo "<button class='button button-primary'>Publish whole site" .
            '</button>';
    }


}
