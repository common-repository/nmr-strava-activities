<?php
/*
Plugin Name: NMR Strava activities
Plugin URI: https://namir.ro/strava-activities/
Description: Get a copy of Strava activities (webhook) as they are recorded in Strava. You must setup a Strava API Application. Users must allow your Strava application to access (read) their data.
Author: Mircea N.
Text Domain: nmr-strava-activities
Domain Path: /languages/
Version: 1.0.6
*/

include_once 'base-nmr.php';
class StravaActivitiesNmr extends BaseNmr
{
    private static $nmr_strava_activities_db_version = '1.3';
    public function __construct()
    {
        $this->init_plugin();
    }

    function init_plugin()
    {
        register_activation_hook(__FILE__, ['StravaActivitiesNmr', 'install']);
        add_action('plugins_loaded', ['StravaActivitiesNmr', 'update_db_check']);
        add_action('wp_ajax_nopriv_nmr-strava-callback', [$this, 'strava_callback']);
        add_action('wp_ajax_nmr-strava-setup-callback', [$this, 'strava_setup_callback']);
        add_action('admin_menu', ['StravaActivitiesNmr', 'setup_admin_menu']);
        add_action('admin_init', ['StravaActivitiesNmr', 'init_admin_menu']);
        add_action('admin_enqueue_scripts', ['StravaActivitiesNmr', 'scripts_for_admin_page']);
        add_action('init', ['StravaActivitiesNmr', 'shortcodes_init']);
    }

    /**
     * Central location to create all shortcodes.
     */
    static function shortcodes_init()
    {
        add_shortcode('strava_nmr_connect', ['StravaActivitiesNmr', 'strava_nmr_connect_func']);
        add_shortcode('strava_nmr_disconnect',  ['StravaActivitiesNmr', 'strava_nmr_disconnect_func']);
        add_shortcode('strava_nmr', ['StravaActivitiesNmr', 'strava_nmr_func']);
        add_shortcode('strava_nmr_table', ['StravaActivitiesNmr', 'strava_nmr_table_func']);
    }

    static function strava_nmr_table_func($atts = [], $content = null, $tag = '')
    {
        global $wpdb;
        $a = shortcode_atts([
            'top' => ''
        ], $atts);
        $output = '<table><tr><th>Type</th><th>Name</th><th>Distance</th><th>Minutes</th></tr>';
        $tablename = $wpdb->prefix . "nmr_strava_activities";
        $top = 100;
        if ($a['top'] > '') {
            $top = intval($a['top']);
            if ($top < 0) {
                $top = 100;
            }
        }
        $sql = $wpdb->prepare("SELECT * FROM {$tablename} ORDER BY id_local DESC LIMIT %d", $top);
        $results = $wpdb->get_results($sql, ARRAY_A);
        if ($results) {
            foreach ($results as $row) {
                $distance = number_format($row['distance'] / 1000.0, 2);
                $elapsed_time = number_format($row['elapsed_time'] / 60.0, 2);
                $output .= "<tr><td>{$row['type']}</td><td>{$row['name']}</td><td>{$distance}</td><td>{$elapsed_time}</td></tr>";
            }
        }
        $output .= '</table>';
        return $output;
    }

    static function strava_nmr_func($atts = [], $content = null, $tag = '')
    {
        global $wpdb;
        $a = shortcode_atts(
            array(
                'read' => 'first_name',
                'login_text' => 'Please login',
                'strava_url_text' => 'Connect to Strava',
                'succes_message' => 'Connected to Strava!',
                'require_login' => '0'
            ),
            $atts
        );
        $options = get_option('nmr_strava_settings');
        $require_login = intval($a['require_login']) > 0;
        if ($require_login) {
            if (!is_user_logged_in()) {
                return $a['login_text'];
            }
            $user_id = get_current_user_id();
            $user_row = self::getUserRow($user_id);
            $strava_user_id = $user_row['strava_user_id'];
        } else {
            $user_id = null;
            $strava_user_id = -1;
        }

        include_once 'strava-client-nmr.php';
        $strava_client = new StravaClientNmr(false, $strava_user_id, $options);
        $provider = $strava_client->get_provider();
        $option_name = "nmr-strava-{$_GET['state']}";
        $state = get_option($option_name, false);
        if (!isset($_GET['code'])) {
            // If we don't have an authorization code then get one
            $authUrl = $provider->getAuthorizationUrl($strava_client->get_scopes());
            update_option("nmr-strava-{$provider->getState()}", $provider->getState());
            $link = "<a href=\"{$authUrl}\">{$a['strava_url_text']}</a>";
            return  $link;
            // Check given state against previously stored one to mitigate CSRF attack
        } elseif (empty($_GET['state']) || $_GET['state'] !== $state) {
            delete_option($option_name);
            return 'Invalid state';
        } else {
            try {
                // Try to get an access token (using the authorization code grant)
                $token = $provider->getAccessToken('authorization_code', [
                    'code' => sanitize_key($_GET['code'])
                ]);
                $user = $provider->getResourceOwner($token);
                $athlete_id = $user->getId();
                //save athlete_id, user_id pair
                $user_array = $user->toArray();
                $userJson = json_encode($user_array);
                $token_array = $token->jsonSerialize();
                self::save_user(intval($athlete_id), $user_id, $userJson, $token_array);
                self::log("connected to strava", $user_id, $athlete_id);
                $result = $a['succes_message'];
            } catch (Exception $e) {
                $result = $e->getMessage();
                self::log("failed to connect to strava", $user_id);
            }
            delete_option($option_name);
            return $result;
        }
    }

    static function save_user($strava_user_id, $user_id, $strava_user_json, $strava_token_array)
    {
        global $wpdb;
        $user_decoded = json_decode($strava_user_json, true);
        $wpdb->replace(
            self::$tables['users'],
            [
                'strava_user_id' => $strava_user_id,
                'user_id' => $user_id,
                'strava_user' => $strava_user_json,
                'strava_token' => json_encode($strava_token_array),
                's_username' => $user_decoded['username'],
                's_firstname' => $user_decoded['firstname'],
                's_lastname' => $user_decoded['lastname'],
                's_profile' => $user_decoded['profile'],
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    static function strava_nmr_disconnect_func($atts = [], $content = null, $tag = '')
    {
        // normalize attribute keys, lowercase
        $atts = array_change_key_case((array) $atts, CASE_LOWER);
        // override default attributes with user attributes
        $a = shortcode_atts(
            array(
                'url' => '/login/',
                'url_text' => 'Profile',
                'success_text' => 'Succesfull deauthorized from Strava!'
            ),
            $atts,
            $tag
        );
        if (!is_user_logged_in()) {
            return self::do_shortcode_private($content);
        }
        $user_id = get_current_user_id();
        $user_row = self::getUserRow($user_id);
        if ($user_row) {
            $strava_user_id = $user_row['strava_user_id'];
            $token_array = json_decode($user_row['strava_token'], true);
            $is_connected = count($token_array) > 0;
            $o = "<a href=\"{$a['url']}\">{$a['url_text']}</a>";
            if ($is_connected) {
                $options = get_option('nmr_strava_settings');
                include_once 'strava-client-nmr.php';
                $strava_client = new StravaClientNmr($token_array, $strava_user_id, $options);
                $response = $strava_client->deauthorize();
                // Check the response code
                $response_code = wp_remote_retrieve_response_code($response);
                if ($response_code < 400) {
                    self::delete_user($strava_user_id);
                    self::log("strava Deauthorized user_id:{$user_id}", $user_id, $strava_user_id);
                    $o = "{$a['success_text']} <a href=\"{$a['url']}\">{$a['url_text']}</a>";
                }
            }
        }
        $o .= self::do_shortcode_private($content);
        return $o;
    }

    static function delete_user($strava_user_id)
    {
        global $wpdb;
        $wpdb->delete(self::$tables['users'], ['strava_user_id' => $strava_user_id], ['%d']);
    }

    /**
     * Shortcode [strava_nmr_connect url url_text connected_text url_disconnect disconnect_text login_text]
     *
     * @param array $atts
     * @param [type] $content
     * @param string $tag
     * @return string
     */
    static function strava_nmr_connect_func($atts = [], $content = null, $tag = '')
    {
        // normalize attribute keys, lowercase
        $atts = array_change_key_case((array) $atts, CASE_LOWER);
        // override default attributes with user attributes
        $a = shortcode_atts(
            array(
                'url' => '/',
                'url_text' => 'Connect to Strava',
                'connected_text' => 'Connected to Strava',
                'url_disconnect' => '/strava-disconect/',
                'disconnect_text' => 'Disconnect',
                'login_text' => 'Please login'
            ),
            $atts,
            $tag
        );
        if (!is_user_logged_in()) {
            return $a['login_text'];
        }
        $user_id = get_current_user_id();
        $token_array = self::getToken($user_id);
        $is_connected = $token_array !== false;
        $o = "<a href=\"{$a['url']}\">{$a['url_text']}</a>";
        if ($is_connected) {
            $o = "{$a['connected_text']} | <a target=\"_blank\" href=\"{$a['url_disconnect']}\">{$a['disconnect_text']}</a>";
        }
        // enclosing tags
        if (!is_null($content)) {
            // secure output by executing the_content filter hook on $content
            //$o .= apply_filters('the_content', $content);
            // run shortcode parser recursively
            $o .= do_shortcode($content);
        }
        return $o;
    }

    static function getToken($user_id)
    {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT strava_token FROM " . self::$tables['users'] . " WHERE user_id=%d", $user_id);
        $json = $wpdb->get_var($sql);
        if (is_string($json) && $json > '') {
            $token_array = json_decode($json, true);
            return $token_array;
        }
        return false;
    }

    static function getUserRow($user_id)
    {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT * FROM " . self::$tables['users'] . " WHERE user_id=%d", $user_id);
        $result = $wpdb->get_row($sql, ARRAY_A);
        if (null === $result) {
            return false;
        }
        return $result;
    }

    static function scripts_for_admin_page($hook_suffix)
    {
        if (strpos($hook_suffix, 'settings_page_nmr-strava-settings-admin') === false) {
            return;
        }
        wp_enqueue_script(
            'admin-strava-nmr',
            plugin_dir_url(__FILE__) . 'admin-strava-nmr.js',
            array('jquery'),
            '1.1'
        );
        wp_localize_script('admin-strava-nmr', 'stravanmrapi', array('get_url' => admin_url('admin-ajax.php')));
    }

    static function install()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $tables = self::$tables;
        // clean-up previous unused options with the name of: nmr-strava-%
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'nmr-strava-%'"
        );
        $tables[] = "CREATE TABLE {$tables['updates']} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            posted_data text NOT NULL,
            date_added datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY  (id)
        ) $charset_collate;";
        $tables[] = " CREATE TABLE {$tables['activities']} (
            id_local bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            id bigint(20) UNSIGNED NOT NULL,
            external_id tinytext NOT NULL,
            upload_id bigint(20) UNSIGNED NOT NULL,
            athlete_id bigint(20) UNSIGNED NOT NULL,
            name tinytext NOT NULL,
            distance decimal(18,2) NOT NULL,
            moving_time int(11) DEFAULT NULL,
            elapsed_time int(11) DEFAULT NULL,
            total_elevation_gain decimal(18,2) DEFAULT NULL,
            type varchar(50) NOT NULL,
            start_date datetime NOT NULL,
            start_date_local datetime NOT NULL,
            timezone tinytext NOT NULL,
            raw_activity text DEFAULT NULL,
            date_added datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY  (id_local),
            KEY idx_id (id),
            KEY athlete_id (athlete_id)
        ) $charset_collate;";
        $tables[] = "CREATE TABLE {$tables['users']} (
            strava_user_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NULL,
            strava_user text DEFAULT NULL,
            strava_token text DEFAULT NULL,
            s_username text  DEFAULT NULL,
            s_firstname text  DEFAULT NULL,
            s_lastname text  DEFAULT NULL,
            s_profile text  DEFAULT NULL,
            date_added datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY  (strava_user_id),
            UNIQUE KEY user_id (user_id)
        ) $charset_collate;";
        $tables[] = "CREATE TABLE {$tables['logs']} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            strava_user_id bigint(20) UNSIGNED DEFAULT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            message text DEFAULT NULL,
            date_added datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY  (id),
            KEY strava_user_id (strava_user_id),
            KEY user_id (user_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($tables);
        update_option('nmr_strava_activities_db_version', self::$nmr_strava_activities_db_version);
    }

    static function update_db_check()
    {
        if (
            get_site_option('nmr_strava_activities_db_version')
            != self::$nmr_strava_activities_db_version
        ) {
            self::install();
        }
    }

    static function setup_admin_menu()
    {
        add_options_page(
            __('Strava Settings', 'nmr-strava-activities'),
            __('Strava NMR', 'nmr-strava-activities'),
            'manage_options',
            'nmr-strava-settings-admin',
            ['StravaActivitiesNmr', 'settings_option_page']
        );
    }

    static function settings_option_page()
    {
        // show error/update messages
        settings_errors('nmr_strava_messages');
?>
        <form action='options.php' method='post'>
            <h2><?php echo __('Strava activities settings', 'nmr-strava-activities'); ?></h2>
            <?php
            settings_fields('nmr_strava_settings_group');
            do_settings_sections('nmr-strava-settings-admin');
            submit_button();
            ?>
        </form>
    <?php
    }

    static function init_admin_menu()
    {
        $page_slug = 'nmr-strava-settings-admin';
        register_setting('nmr_strava_settings_group', 'nmr_strava_settings');
        add_settings_section(
            "nmr_strava_section_id",
            __('Strava settings', 'nmr-strava-activities'),
            ['StravaActivitiesNmr', 'settings_section_callback'],
            $page_slug
        );

        add_settings_field(
            'clientId',
            __('Strava client id', 'nmr-strava-activities'),
            ['StravaActivitiesNmr', 'clientId_render'],
            $page_slug,
            "nmr_strava_section_id"
        );
        add_settings_field(
            'clientSecret',
            __('Strava client secret', 'nmr-strava-activities'),
            ['StravaActivitiesNmr', 'clientSecret_render'],
            $page_slug,
            "nmr_strava_section_id"
        );
        add_settings_field(
            'redirectUri',
            __('Redirect URI', 'nmr-strava-activities'),
            ['StravaActivitiesNmr', 'redirectUri_render'],
            $page_slug,
            "nmr_strava_section_id"
        );
        add_settings_field(
            'webhook_callback_url',
            __('Webhook callback url', 'nmr-strava-activities'),
            ['StravaActivitiesNmr', 'webhook_callback_url_render'],
            $page_slug,
            "nmr_strava_section_id"
        );
        add_settings_field(
            'verify_token',
            __('Verify token', 'nmr-strava-activities'),
            ['StravaActivitiesNmr', 'verify_token_render'],
            $page_slug,
            "nmr_strava_section_id"
        );

        add_settings_section(
            "nmr_strava_section_status_id",
            __('Strava status', 'nmr-strava-activities'),
            ['StravaActivitiesNmr', 'status_section_callback'],
            $page_slug
        );
    }

    static function settings_section_callback($args)
    {
        echo __('Settings found on your <a href="https://www.strava.com/settings/api" target="_blank">strava application</a>', 'nmr-strava-activities');
    }

    static function status_section_callback()
    {
        $key = 'verify_token';
        $value = self::local_get_option($key);
    ?>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php echo __('Plugin status', 'nmr-strava-activities'); ?></th>
                    <td><?php echo self::get_subscription_status(); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo __('Activate Strava Webhook', 'nmr-strava-activities'); ?></th>
                    <td><button type="button" name="btn_activate" id="btn_activate" class="button button-secondary">Save settings and Activate Strava Webhook</button></td>
                    <?php wp_nonce_field('init_webhook', 'init_stravanmr_nonce') ?>
                </tr>
                <tr>
                    <th scope="row"><?php echo __('Deactivate Strava Webhook', 'nmr-strava-activities'); ?></th>
                    <td><button type="button" name="btn_deactivate" id="btn_deactivate">Deactivate</button></td>
                    <?php wp_nonce_field('init_webhook', 'init_stravanmr_nonce') ?>
                </tr>
            </tbody>
        </table>
<?php
    }

    static function get_subscription_status()
    {
        $options = get_option('nmr_strava_settings');
        include_once 'strava-client-nmr.php';
        $client = new StravaClientNmr(false, false, $options);
        $response = $client->get_subscription_status();
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }
        $body = json_decode($response['body'], true);
        if (isset($body[0]['id'])) {
            if ($options['subscription_id'] != $body[0]['id']) {
                // save subscription_id to wp options
                $options['subscription_id'] = intval($body[0]['id']);
                update_option('nmr_strava_settings', $options);
            }
            return "Strava webhook subscription id = {$body[0]['id']}";
        }
        if (is_array($body) && count($body) == 0) {
            return "Strava webhook subscription not found";
        }
        return $response['body'];
    }

    static function input_render($name, $value)
    {
        $name_esc = esc_attr("nmr_strava_settings[{$name}]");
        echo '<input id="' . $name_esc . '" width="500" class="large-text" name="' . $name_esc . '" value="' . esc_textarea($value) . '">';
    }

    static function clientId_render()
    {
        $key = 'clientId';
        StravaActivitiesNmr::input_render($key, self::local_get_option($key));
    }

    static function clientSecret_render()
    {
        $key = 'clientSecret';
        StravaActivitiesNmr::input_render($key, self::local_get_option($key));
    }

    static function local_get_option($key)
    {
        $options = get_option('nmr_strava_settings');
        $value = '';
        if (is_array($options)) {
            if (array_key_exists($key, $options)) {
                $value = $options[$key];
            }
        }
        return $value;
    }

    static function redirectUri_render()
    {
        $key = 'redirectUri';
        StravaActivitiesNmr::input_render($key, self::local_get_option($key));
    }

    static function webhook_callback_url_render()
    {
        $key = 'webhook_callback_url';
        $options = get_option('nmr_strava_settings');
        if (!is_array($options)) {
            $options = array();
        }
        if (
            !array_key_exists($key, $options) ||
            !filter_var($options[$key], FILTER_VALIDATE_URL)
        ) {
            $options[$key] = admin_url('admin-ajax.php') . "?action=nmr-strava-callback&";
            update_option('nmr_strava_settings', $options);
        }
        StravaActivitiesNmr::input_render($key, $options[$key]);
    }

    static function verify_token_render()
    {
        $key = 'verify_token';
        $options = get_option('nmr_strava_settings');
        if (!is_array($options)) {
            $options = array();
        }
        if (
            !array_key_exists($key, $options) ||
            trim($options[$key]) <= ''
        ) {
            $options[$key] =  "nmr-verify-strava-token";
            update_option('nmr_strava_settings', $options);
        }
        StravaActivitiesNmr::input_render($key, $options[$key]);
    }

    private function get_key($search_key)
    {
        foreach ($_GET as $key => $value) {
            if (stristr($key, $search_key) !== FALSE) {
                return $key;
            }
        }
        return $search_key;
    }

    public function strava_callback()
    {
        switch ($_SERVER["REQUEST_METHOD"]) {
            case 'GET':
                $data = [
                    'hub_mode' => sanitize_title($_GET[$this->get_key('hub_mode')]),
                    'hub_challenge' => sanitize_key($_GET[$this->get_key('hub_challenge')]),
                    'hub_verify_token' => sanitize_key($_GET[$this->get_key('hub_verify_token')]),
                ];
                $this->verify_strava_subscription($data);
                break;
            case 'POST':
                $data = file_get_contents('php://input');
                $this->handle_strava_update($data);
                break;
        }
        wp_send_json('what did you want?', 403);
    }

    public function strava_setup_callback()
    {
        switch ($_SERVER["REQUEST_METHOD"]) {
            case 'PUT':
                $data = [];
                parse_str(file_get_contents('php://input'), $data);
                if (!isset($data['init_stravanmr_nonce']) || !wp_verify_nonce($data['init_stravanmr_nonce'], 'init_webhook')) {
                    wp_send_json('nonce verification failed', 404);
                }
                $this->update_options($data);
                $this->init_webhook($data);
                break;
            case 'DELETE':
                $data = file_get_contents('php://input');
                $this->delete_webhook($data);
                break;
        }
        wp_send_json('what did you want?', 403);
    }

    private function update_options($options)
    {
        $opt = $options['nmr_strava_settings'];
        if ($this->check_options($opt)) {
            update_option('nmr_strava_settings', $opt);
        }
    }

    function delete_webhook($data)
    {
        $options = $this->check_options(get_option('nmr_strava_settings'));
        if (!$options) {
            wp_send_json('not all optiones were set', 404);
        }
        if (
            !array_key_exists('subscription_id', $options)
            ||  $options['subscription_id'] < 1
        ) {
            wp_send_json('no subscription id', 404);
        }
        $subscription_id = $options['subscription_id'];
        $result = [];
        mb_parse_str($data, $result);
        if (!isset($result['init_stravanmr_nonce']) || !wp_verify_nonce($result['init_stravanmr_nonce'], 'init_webhook')) {
            wp_send_json('nonce verification failed', 404);
        }
        include_once 'strava-client-nmr.php';
        $client = new StravaClientNmr(false, false, $options);
        $response = $client->deleteSubscription($subscription_id);
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            wp_send_json("Something went wrong: $error_message", 400);
        }
        $options['subscription_id'] = null;
        update_option('nmr_strava_settings', $options);
        wp_send_json('removed subscription', 204);
    }

    function init_webhook($data)
    {
        $options = $this->check_options(get_option('nmr_strava_settings'));
        if (!$options) {
            wp_send_json('not all optiones were set', 404);
        }
        include_once 'strava-client-nmr.php';
        $client = new StravaClientNmr(false, false, $options);
        $response = $client->createSubscription();
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            wp_send_json("Something went wrong: $error_message", 400);
        }
        // Check the response code
        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode($response['body'], true);
        if (!isset($body['id']) || 399 < $response_code) {
            wp_send_json(json_encode($body['errors']), 400);
        }
        $options['subscription_id'] = $body['id'];
        update_option('nmr_strava_settings', $options);
        wp_send_json('received subscription id', 200);
    }


    function handle_strava_update($dataString)
    {
        global $wpdb;
        $data = json_decode($dataString, true);
        if (!is_array($data)) {
            $this->set_error("no strava data? received:{$dataString}");
            wp_send_json('no data?', 200);
        }
        $options = $this->check_options(get_option('nmr_strava_settings'));
        if (!$options) {
            wp_send_json('ok', 200);
        }

        include_once 'strava-activities-repo.php';
        $repo = new StravaActivitiesRepo($_SERVER["REQUEST_METHOD"], $data, $options);
        $repo->add_strava_update($dataString);

        //handle activity data
        if (array_key_exists('object_type', $data)) {
            if ($data['object_type'] == 'activity') {
                if ('create' == $data['aspect_type']) {
                    $activity_id = $data['object_id'];
                    $owner_id = $data['owner_id'];
                    $subscription_id = $data['subscription_id'];
                    $own_subscription_id = $options['subscription_id'];
                    if ($subscription_id != $own_subscription_id) {
                        $this->set_error("own_subscription_id={$own_subscription_id} and strava sent {$subscription_id}", null, $owner_id);
                        wp_send_json('mismatch subscription id', 404);
                    }
                    $tables = self::$tables;
                    $sql = $wpdb->prepare("SELECT * 
                        FROM {$tables['users']} 
                        WHERE strava_user_id=%d", $owner_id);
                    $strava_user = $wpdb->get_row($sql, ARRAY_A);
                    if (null === $strava_user) {
                        $this->set_error("No strava user with id:{$owner_id}");
                        wp_send_json('ok', 200);
                    }
                    $strava_user_id = $owner_id;
                    $user_id = $strava_user['user_id'];
                    $strava_token = $strava_user['strava_token'];
                    if ($strava_token <= '') {
                        $this->set_error("no strava token found for user", $user_id, $strava_user_id);
                        wp_send_json('ok', 200);
                    }
                    $token_array = json_decode($strava_token, true);
                    $repo->init_oauth($token_array, $strava_user_id);
                    $repo->import_activity($activity_id, $user_id, $strava_user_id);
                } else if ('delete' == $data['aspect_type']) {
                    $activity_id = $data['object_id'];
                    $owner_id = $data['owner_id'];
                    $tables = self::$tables;
                    $sql = $wpdb->prepare("SELECT * 
                        FROM {$tables['users']} 
                        WHERE strava_user_id=%d", $owner_id);
                    $strava_user = $wpdb->get_row($sql, ARRAY_A);
                    if (null === $strava_user) {
                        $this->set_error("No strava user with id:{$owner_id}");
                        wp_send_json('ok', 200);
                    }
                    $strava_user_id = $owner_id;
                    $user_id = $strava_user['user_id'];
                    $repo->delete_segment($activity_id, $strava_user_id, $user_id);
                }
            } else if ($data['object_type'] == 'athlete') {
                if (is_array($data['updates']) && array_key_exists('authorized', $data['updates'])) {
                    $owner_id = $data['owner_id'];
                    $authorized = $data['updates']['authorized'];
                    if (!$authorized) {
                        error_log("nmr strava: removing token for strava_user_id:{$strava_user_id}");
                        $repo->delete_token($owner_id);
                    }
                }
            } else {
                //error_log("nmr strava: strava sent: {$dataString}");
            }
        } else {
            //error_log("nmr strava: strava sent: {$dataString}");
        }
        //always send HTTP 200
        wp_send_json('ok', 200);
    }

    private function check_options($options)
    {
        if (!is_array($options)) {
            $this->set_error("no strava settings found");
            return false;
        }
        $keys = ['clientId', 'clientSecret', 'redirectUri', 'webhook_callback_url', 'verify_token'];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $options)) {
                $this->set_error("setting key {$key} not found", null, null, $options);
                return false;
            }
        }
        return $options;
    }

    public function get_verify_token()
    {
        $key = 'verify_token';
        $options = get_option('nmr_strava_settings');
        if (!is_array($options)) {
            $options = array();
        }
        if (
            !array_key_exists($key, $options) ||
            trim($options[$key]) <= ''
        ) {
            $options[$key] =  "nmr-verify-strava-token";
            update_option('nmr_strava_settings', $options);
        }
        return $options[$key];
    }

    private function get_token_from_strava($data)
    {
        if (!is_array($data)) {
            return false;
        }
        foreach ($data as $key => $value) {
            if (false !== strpos($key, 'hub_verify_token')) {
                return $value;
            }
        }
        return false;
    }
    function verify_strava_subscription($data)
    {
        $VERIFY_TOKEN = $this->get_verify_token();
        $mode = $data['hub_mode'];
        $token = $this->get_token_from_strava($data);
        $challenge = $data['hub_challenge'];
        if ($mode === 'subscribe' && $token === $VERIFY_TOKEN) {
            $this->set_error('token verified ok');
            wp_send_json(array('hub.challenge' => $challenge), 200);
        }
        $this->set_error('token do not match');
        wp_send_json('token do not match', 403);
    }

    static function do_shortcode_private($content)
    {
        $o = '';
        if (!is_null($content)) {
            // secure output by executing the_content filter hook on $content
            $o = apply_filters('the_content', $content);
            // run shortcode parser recursively
            $o = do_shortcode($o);
        }
        return $o;
    }
}
BaseNmr::$tables = [
    'updates' => "{$wpdb->prefix}nmr_strava_updates",
    'activities' => "{$wpdb->prefix}nmr_strava_activities",
    'users' => "{$wpdb->prefix}nmr_strava_users",
    'logs' => "{$wpdb->prefix}nmr_strava_logs",
];
$nmrStravaActivities = new StravaActivitiesNmr();
