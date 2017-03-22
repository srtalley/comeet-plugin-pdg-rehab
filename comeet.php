<?php
/*
 * Plugin Name: Comeet
 * Plugin URI: http://www.comeet.co
 * Description: Job listing page using the Comeet API.
 * Version: 1.2.1
 * Author: Comeet
 * Author URI: http://www.comeet.co
 * License: Apache 2
 */


/*

Copyright 2016 Comeet

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

   http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.

*/

include_once(ABSPATH . 'wp-admin/includes/plugin.php');
//error_reporting(E_ALL);
//ini_set('display_errors', 1);
$plugin_dir = trailingslashit(plugin_dir_path(__FILE__));
require_once($plugin_dir . 'includes/lib/comeet-data.php');


if (!class_exists('Comeet')) {

    class Comeet {


        var $plugin_url;
        var $plugin_dir;

        var $db_opt = 'Comeet_Options';


        private $isComeetContentPage;
        private $comeet_pos;
        private $post_data;
        private $socialGraphTitle;
        private $socialGraphImage;
        private $socialGraphDescription;
        private $socialGraphDefaultDescription = 'Job Opportunities';



        public function __construct() {
            $this->plugin_url = trailingslashit(WP_PLUGIN_URL . '/' . dirname(plugin_basename(__FILE__)));
            $this->plugin_dir = trailingslashit(plugin_dir_path(__FILE__));

            if (is_admin()) {
                add_action('admin_init', array($this, 'register_settings'));
                add_action('admin_menu', array($this, 'options_page'));
                add_action('admin_init', array($this, 'flush_permalinks'));
            } else {
                //add_filter('the_content', array($this, 'comeet_content'));
                add_filter('template_include', array($this, 'career_page_template'), 99);
                add_shortcode('comeet_data', array($this, 'comeet_content'));
                add_shortcode('comeet_page', array($this, 'comeet_custom_shortcode'));


            }

            add_action('the_posts', array($this, 'set_is_comeet_content_page'), 10);
            add_filter('wpseo_og_og_title', array($this, 'filter_og_title'));
            add_action('wp_head', array($this, 'update_header'), 12);
            //add_filter('document_title_parts', array($this,'filter_title'));
            add_filter('wpseo_title', array($this, 'filter_title_simple'));
            add_filter('wpseo_opengraph_image', array($this, 'filter_image'));
            add_filter('wpseo_canonical', array($this, 'filter_url'));
            add_filter('wpseo_metadesc', array($this, 'getSocialGraphDescription'));
            add_filter('wpseo_opengraph_desc', array($this, 'getSocialGraphDescription'));



        }

        public function getSocialGraphDescription($pageSetDescription = null) {
            if (!$this->isComeetContentPage) {
                $res = $pageSetDescription;
            } else if ($this->socialGraphDescription) {
                $res = $this->socialGraphDescription;
            } else if ($pageSetDescription != null) {
                $res = $pageSetDescription;
            } else {
                $res = $this->socialGraphDefaultDescription;
            }

            return strip_tags($res);
        }

        function filter_image($imageUrl) {
            if (isset($this->socialGraphImage)) {
                $imageUrl = $this->socialGraphImage;
            }
            return $imageUrl;
        }

        function set_is_comeet_content_page($posts) {

            $this->isComeetContentPage = false;
            for ($c = 0; $c < count($posts); $c++) {
                if (has_shortcode($posts[$c]->post_content, 'comeet_data') || has_shortcode($posts[$c]->post_content, 'comeet_page')) {
                    $this->isComeetContentPage = true;
                    break;
                }
            }
            if ($this->isComeetContentPage) {
                global $wp_query;
                if (isset($wp_query->query_vars['comeet_pos'])) {
                    $this->comeet_pos = urldecode($wp_query->query_vars['comeet_pos']);
                } else {
                    $this->comeet_cat = (isset($wp_query->query_vars['comeet_cat'])) ? urldecode($wp_query->query_vars['comeet_cat']) : null;
                }
                $this->comeet_preload_data();
            }
            return $posts;
        }


        function update_header() {

            if ($this->isComeetContentPage && (!is_plugin_active('wordpress-seo/wp-seo.php'))) {
                ?>

                <!-- COMEET PLUGIN -->
                <?php
                if (isset($this->title)) {
                    ?><meta name="og:title" content="<?= $this->title ?>"/>
                    <?php
                }
                if (isset($this->socialGraphImage)) {
                    ?><meta property="og:image" content="<?= $this->socialGraphImage ?>"/><?php

                }
                ?><meta property="og:description" content="<?= $this->getSocialGraphDescription() ?>">
                <meta property="og:url" content="<?= $this->get_current_url(); ?>"/>
                <meta property="og:type" content="article" />

                <!-- COMEET PLUGIN -->
                <?php
            }
        }


        function career_page_template($template) {
            $new_template = '';
            global $wp_query;
            $options = $this->get_options();
            if (isset($wp_query->query_vars['comeet_pos'])) {
                $new_template = locate_template(array($options['comeet_positionpage_template']));
            } elseif (isset($wp_query->query_vars['comeet_cat'])) {
                $new_template = locate_template(array($options['comeet_subpage_template']));
            }
            if ('' != $new_template) {
                return $new_template;
            }

            return $template;
        }

        public function install() {
            $this->get_options();
        }

        public function deactivate() {

        }

        public function check_for_keys() {

            if (is_admin()) {
                if ((empty($this->comeet_token) || empty($this->comeet_uid)) && empty($_POST['save'])) {
                    add_action('admin_notices', array($this, 'admin_keys_notice'));
                }
            }
        }

        public function admin_keys_notice() {
            $message = '';
            if (empty($this->comeet_token)) $message = 'Almost done! Just enter your <b>Comeet Token</b> in the ';
            if (empty($this->comeet_uid)) $message = 'Almost done! Just enter your <b>Comeet UID</b> in the ';
            echo '<div class="updated"><p>' . $message . ' <a href="' . admin_url('admin.php?page=comeet') . '">settings</a></p></div>';
        }

        public function check_for_curl() {
            //echo 'Curl: ', function_exists('curl_version') ? 'Enabled' : 'Disabled';
            if (is_admin()) {
                if (!in_array('curl', get_loaded_extensions())) {
                    if ($_GET['page'] == 'comeet') {
                        add_action('admin_notices', array($this, 'admin_curl_notice'));
                    }
                }
            }
        }

        public function check_for_comeetapi() {

            if (is_admin()) {

                if ((!empty($this->comeet_token) && !empty($this->comeet_uid))) {
                    add_action('admin_notices', array($this, 'admin_comeet_api_notice'));
                }
            }
        }

        public function admin_curl_notice() {
            $message = 'The Comeet plugin may not function properly as cURL is not enabled on the server.<br /><br />Ensure that Curl for php is enabled and that your server can execute http requests to www.comeet.co (used to retrieve the positions data).';
            echo '<div class="error"><p>' . $message . '</p></div>';
            echo '<div id="message" class="updated">
		<h3>How to enable cURL on your server?</h3>
		<p>If you are seeing this error message, the best way to resolve the problem is to ask your hosting provider or system admin to enable cURL on the server. If you are on your own, then the following tips may work:</p>
		<p><strong>Option 1 : Enable CURL via the php.ini</strong></p>

		<p>This is the main method on any windows install like WAMP, XAMPP etc.</p>

		<ol>
		<li>Locate  your PHP.ini file (normally located at in the bin folder of your apache install)</li>
		<li>Open the PHP.ini in notepad</li>
		<li>Search or find the following : �;extension=php_curl.dll�</li>
		<li>Uncomment this by removing the semi-colon �;� before it</li>
		<li>Save and Close PHP.ini</li>
		<li>Restart Apache</li>
		</ol>

		<p><strong>Option 2: Enabling CURL in WAMP</strong></p>

		<ol>
		<li>Left-click on the WAMP server icon in the bottom right of the screen</li>
		<li>PHP -> PHP Extensions -> php_curl</li>
		</ol>

		<p><strong>Option 3: enable CURL in Ubuntu</strong></p>

		<p>Run the following command:</p>

		<ol>
		<li>sudo apt-get install php5-curl</li>
		<li>sudo service apache2 restart</li>
		</ol>
		<p><i>Note: Each server is setup differently and depending on the setup these instructions may not work. But in most cases, these instructions should help.</i> </p>
		</div>';
        }

        public function admin_comeet_api_notice() {
            $apiurl = 'https://www.comeet.co/careers-api/1.0/company/' . $this->comeet_uid . '/positions?token=' . $this->comeet_token;
            $request = wp_remote_get($apiurl);
            $response = $request['response'];
            if ($response['code'] != 200) {
                $jsonresponse = json_decode($request['body']);
                $message = $jsonresponse->message;
                if (strlen(trim($message)) != 0) {
                    echo '<div class="error"><p>Settings saved but there was an error retrieving positions data: ' . $message . '</p></div>';
                } else {
                    $message = 'Comeet - Unexpected error retrieving positions data. If the problem persists please contact us at: <a href="mailto:support@comeet.co" target="_blank">support@comeet.co</a>';
                    echo '<div class="error"><p>' . $message . '</p></div>';
                }
            } else {
                if (isset($_GET['settings-updated'])) {
                    echo '<div id="message" class="updated"><p>Your career pages are now ready. In case you are unable to view the career pages please open the Permalinks settings and click <i>Save</i>.</p></div>';
                }
            }
            /* 				if ($response['code'] == 500 || $response['code'] == 204) {
                                $message = 'Comeet - Unexpected error retrieving positions data. If the problem persists please contact us at: <a href="mailto:support@comeet.co" target="_blank">support@comeet.co</a>';
                                echo '<div class="error"><p>'.$message.'</p></div>';
                            }; */
        }

        /**
         * Gets plugin config options
         *
         * @access public
         * @return array
         */
        public function get_options() {
            $options = array(
                'comeet_token' => '',
                'comeet_uid' => '',
                'location' => '',
                'post_id' => '',
                'advanced_search' => 1,
                'comeet_color' => '278fe6',
                'comeet_bgcolor' => '',
                'comeet_stylesheet' => 'comeet-cards.css',
                'comeet_subpage_template' => 'page.php',
                'comeet_positionpage_template' => 'page.php'
            );

            $saved = get_option($this->db_opt);

            if (!empty($saved)) {
                foreach ($saved as $key => $option) {
                    $options[$key] = $option;
                }
            }

            if ($saved != $options) {
                update_option($this->db_opt, $options);
            }

            return $options;
        }

        /**
         * Registers the options page.
         */
        function options_page() {
            add_options_page('Comeet Settings', 'Comeet', 'manage_options', 'comeet', array($this, 'handle_options'));
        }

        function flush_permalinks() {
            if (isset($_GET['settings-updated'])) {
                flush_rewrite_rules(false);
                //echo '<div id="message" class="updated"><p><b>Settings have been saved.</b> In case you are unable to view the career pages please open the Permalinks settings and click <i>Save</i>.</p></div>';
            }
        }

        function register_settings() {

            register_setting('comeet_options', $this->db_opt, array($this, 'validate_options'));
            $options = $this->get_options();
            // Fetch the integration settings
            $this->comeet_token = $options['comeet_token'];
            $this->comeet_uid = $options['comeet_uid'];

            // check if token and UID are both entered
            $this->check_for_keys();

            // check if cURL is installed on the server
            $this->check_for_curl();


            // check if API returns a proper response
            $this->check_for_comeetapi();

            $post = get_post($options['post_id']);

            $post_parents = get_post_ancestors($post);

            if (!empty($post_parents)) {
                $parent_posts_slug = array();

                foreach ($post_parents as $parent_id) :
                    $parent = get_post($parent_id);
                    $parent_posts_slug[] = $parent->post_name;
                endforeach;
            }

            if (!empty($parent_posts_slug)) {
                $page_parents = (count($parent_posts_slug) > 1 ? implode('/', array_reverse($parent_posts_slug)) : reset($parent_posts_slug));
                add_rewrite_rule($page_parents . '/' . $post->post_name . '/([^/]+)/([^/]+)/([^/]+)/?$', 'index.php?pagename=' . $page_parents . '/' . $post->post_name . '&comeet_cat=$matches[1]&comeet_pos=$matches[2]', 'top');
                add_rewrite_rule($page_parents . '/' . $post->post_name . '/([^/]+)/?$', 'index.php?pagename=' . $page_parents . '/' . $post->post_name . '&comeet_cat=$matches[1]', 'top');
            } else {
                add_rewrite_rule($post->post_name . '/([^/]+)/([^/]+)/([^/]+)/?$', 'index.php?pagename=' . $post->post_name . '&comeet_cat=$matches[1]&comeet_pos=$matches[2]', 'top');
                add_rewrite_rule($post->post_name . '/([^/]+)/?$', 'index.php?pagename=' . $post->post_name . '&comeet_cat=$matches[1]', 'top');
            }

            // Comeet API required settings.
            add_settings_section(
                'comeet_api_settings',
                'Company Identifier',
                array($this, 'api_credentials_text'),
                'comeet'
            );

            add_settings_field(
                'comeet_uid',
                'Company UID',
                array($this, 'comeet_uid_input'),
                'comeet',
                'comeet_api_settings'
            );

            add_settings_field(
                'comeet_token',
                'Token',
                array($this, 'comeet_token_input'),
                'comeet',
                'comeet_api_settings'
            );

            add_settings_section(
                'comeet_api_blank',
                '',
                array($this, 'comeet_api_blank'),
                'comeet'
            );
            // Other fields as needed.

            add_settings_section(
                'comeet_other_settings',
                'Settings',
                array($this, 'other_text'),
                'comeet'
            );

            add_settings_field(
                'post_id',
                'Careers website page',
                array($this, 'job_page_input'),
                'comeet',
                'comeet_other_settings'
            );

            add_settings_field(
                'advanced_search',
                'Group Positions By',
                array($this, 'advanced_search_input'),
                'comeet',
                'comeet_other_settings'
            );
            add_settings_field(
                'comeet_stylesheet',
                'Style',
                array($this, 'comeet_stylesheet_input'),
                'comeet',
                'comeet_other_settings'
            );
            add_settings_field(
                'comeet_color',
                'Form Main Color',
                array($this, 'comeet_color_input'),
                'comeet',
                'comeet_other_settings'
            );
            add_settings_field(
                'comeet_bgcolor',
                'Form Background Color',
                array($this, 'comeet_bgcolor_input'),
                'comeet',
                'comeet_other_settings'
            );

            add_settings_section(
                'comeet_other_blank',
                '',
                array($this, 'comeet_other_blank'),
                'comeet'
            );
            //Advanced Section
            add_settings_section(
                'comeet_advanced_settings',
                'Advanced',
                array($this, 'comeet_advanced_text'),
                'comeet'
            );
            add_settings_field(
                'comeet_subpage_template',
                'Template for locations / departments',
                array($this, 'comeet_subpage_input'),
                'comeet',
                'comeet_advanced_settings'
            );
            add_settings_field(
                'comeet_positionpage_template',
                'Template for the position page',
                array($this, 'comeet_positionpage_input'),
                'comeet',
                'comeet_advanced_settings'
            );
            add_settings_section(
                'comeet_advanced_blank',
                '',
                array($this, 'comeet_advanced_blank'),
                'comeet'
            );
//flush_rewrite_rules( true );
        }

        function api_credentials_text() {
            echo '<div class="card" style="margin-bottom: 4em;"><p>To find these values, navigate in Comeet to Company Settings / Careers Website and make sure to enable the API. These settings are available to the company&#39;s admin. <a href="http://support.comeet.co/knowledgebase/careers-website/" target="_blank">Learn More</a></p>';
        }

        function comeet_advanced_text() {
            echo '<div class="card" style="margin-bottom: 4em;"><p>
      Use a different theme by specifying the templates that you would like to use.</br>
      Templates are PHP files that reside in your theme folder. <a target="_blank" href="https://developer.wordpress.org/themes/template-files-section/page-template-files/page-templates/">Learn more about page templates</a>
      </p>';
        }

        function comeet_api_blank() {
            echo '</div>';
        }

        function other_text() {
            echo '<div class="card">';
        }

        function comeet_token_input() {
            $options = $this->get_options();

            echo '<input type="text" id="comeet_token" name="' . $this->db_opt . '[comeet_token]" value="' . $options['comeet_token'] . '" size="25" style="width:200px" />';
        }

        function comeet_uid_input() {
            $options = $this->get_options();

            echo '<input type="text" id="comeet_uid" name="' . $this->db_opt . '[comeet_uid]" value="' . $options['comeet_uid'] . '" size="25"  style="width:200px" />';
        }

        function comeet_other_blank() {
            echo '</div>';
        }

        function comeet_advanced_blank() {
            echo '</div>';
        }

        function job_page_input() {
            // Get a listing of WP pages as candidates for the careers page.
            $pages = get_pages(array('sort_column' => 'sort_column'));
            $options = $this->get_options();
            $post_id = trim($options['post_id']);
            $page_opts = array();
            $page_opts[] = '<option value="-1"' . (empty($options['post_id']) ? ' selected="selected"' : '') . ' style="text-decoration:underline;">Create new page</option>';
            foreach ($pages as $page) {
                $page_opts[] = '<option value="' . $page->ID . '"' . ($options['post_id'] == $page->ID ? ' selected="selected"' : '') . '>' . $page->post_title . '</option>';
            }
            echo '<div><select name="' . $this->db_opt . '[post_id]" id="post_id" style="width:200px">' . implode("\n", $page_opts) . '</select></div>';
            echo '<p class="description">Your careers website homepage will be at this page.</p>';
        }

        function advanced_search_input() {
            $options = $this->get_options();
            echo '<div><select name="' . $this->db_opt . '[advanced_search]" id="advanced_search" style="width:200px"><option value="0"' . ($options['advanced_search'] == 0 ? ' selected="selected"' : '') . '>Location</option><option value="1"' . ($options['advanced_search'] == 1 ? ' selected="selected"' : '') . '>Department</option></select></div>';
        }

        function comeet_color_input() {
            $options = $this->get_options();

            echo '<input type="text" id="comeet_color" name="' . $this->db_opt . '[comeet_color]" value="' . $options['comeet_color'] . '" size="25"  style="width:200px" />';
            echo '<p class="description">Optional. e.g. 278fe6</p>';
        }

        function comeet_bgcolor_input() {
            $options = $this->get_options();

            echo '<input type="text" id="comeet_bgcolor" name="' . $this->db_opt . '[comeet_bgcolor]" value="' . $options['comeet_bgcolor'] . '" size="25"  style="width:200px" />';
            echo '<p class="description">Optional. e.g. eeeeee</p>';
        }

        function comeet_subpage_input() {
            $options = $this->get_options();

            echo '<input type="text" id="comeet_subpage_template" name="' . $this->db_opt . '[comeet_subpage_template]" value="' . $options['comeet_subpage_template'] . '" size="25"  style="width:200px" />';
            echo '<p class="description">Optional. e.g. page.php</p>';
        }

        function comeet_positionpage_input() {
            $options = $this->get_options();

            echo '<input type="text" id="comeet_positionpage_template" name="' . $this->db_opt . '[comeet_positionpage_template]" value="' . $options['comeet_positionpage_template'] . '" size="25"  style="width:200px" />';
            echo '<p class="description">Optional. e.g. page.php</p>';
        }

        function comeet_stylesheet_input() {
            $options = $this->get_options();
            echo '<div>';
            echo '<select name="' . $this->db_opt . '[comeet_stylesheet]" id="comeet_stylesheet" style="width:200px">';
            echo '<option value="comeet-basic.css"' . ($options['comeet_stylesheet'] == 'comeet-basic.css' ? ' selected="selected"' : '') . '>Basic</option>';
            echo '<option value="comeet-cards.css"' . ($options['comeet_stylesheet'] == 'comeet-cards.css' ? ' selected="selected"' : '') . '>Cards</option>';
            echo '<option value="comeet-two-columns.css"' . ($options['comeet_stylesheet'] == 'comeet-two-columns.css' ? ' selected="selected"' : '') . '>Two columns</option>';

            echo '</select></div>';
        }

        /**
         * Validates plugin settings form when submitted.
         *
         * @return array
         */
        function validate_options($input) {
            $valid['comeet_token'] = $input['comeet_token'];
            $valid['comeet_uid'] = trim($input['comeet_uid']);
            $valid['location'] = trim($input['location']);
            $valid['comeet_color'] = trim($input['comeet_color']);
            $valid['comeet_bgcolor'] = trim($input['comeet_bgcolor']);
            $valid['advanced_search'] = intval($input['advanced_search']);
            $valid['comeet_stylesheet'] = $input['comeet_stylesheet'];
            $valid['comeet_subpage_template'] = $input['comeet_subpage_template'];
            $valid['comeet_positionpage_template'] = $input['comeet_positionpage_template'];


            if ($input['post_id'] == '-1') {
                // Create a new page for the job posts to appear.
                if ($post_id = $this->create_new_page()) {
                    $valid['post_id'] = $post_id;
                } else {
                    add_settings_error(
                        $this->db_opt . '[post_id]',
                        'post_id_error',
                        'Failed to create new job page.',
                        'error'
                    );
                }
            } else {
                $valid['post_id'] = $input['post_id'];
            }

            $transient_reset = 'comeet-careers-' . $valid['comeet_uid'] . '-' . $valid['comeet_token'];
            delete_transient($transient_reset);
            return $valid;
        }

        /**
         * Displays the plugin settings form.
         *
         * @access public
         * @return void
         */
        public function handle_options() {
            $settings = $this->db_opt;
            include_once($this->plugin_dir . 'includes/comeet-options.php');
        }

        /**
         * Creates new WP Page to attach the job listings to.
         *
         * @access private
         * @return integer
         */
        private function create_new_page() {
            global $user_ID;
            $page = array(
                'post_type' => 'page',
                'post_content' => '[comeet_data]',
                'post_parent' => 0,
                'post_author' => $user_ID,
                'post_title' => 'Careers',
                'post_name' => 'careers',
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed'
            );
            return wp_insert_post($page);
        }

        function comeet_preload_data() {
            if ($this->isComeetContentPage) {
                if (isset($this->comeet_pos)) {
                    $this->post_data = ComeetData::get_position_data($this->get_options(), $this->comeet_pos);
                    $this->socialGraphDescription = $this->post_data['description'];
                    $this->title = 'Job opportunity: '.$this->post_data['name'];
                    $this->socialGraphTitle = $this->title;
                    $this->socialGraphImage = $this->post_data['picture_url'];
                } else if (isset($this->comeet_cat)) {
                    $options = $this->get_options();
                    list($comeetgroups, $data, $group_element) = ComeetData::get_groups($options, $this->comeet_cat);
                    foreach ($data as $post) {
                        if (strtolower(clean($post[$group_element])) == $this->comeet_cat) {
                            $this->title = $post[$group_element];
                            $this->socialGraphDescription = $this->socialGraphDefaultDescription.' - '.$post[$group_element];
                            break;
                        }
                    }

                }
            }

        }

        function comeet_content($text) {


            $options = $this->get_options();
            if (get_the_ID() == $options['post_id']) {
                $this->add_frontend_css();
                $this->add_frontend_scripts();
                $text .= $this->comeet_add_template();
            }
            return $text;
        }

        function comeet_custom_shortcode($attr, $content = null) {
            $options = $this->get_options();
            $this->add_frontend_css();
            $this->add_frontend_scripts();
            $text = $this->comeet_add_template_custom_shortcode($attr, $content);

            return $text;
        }

        protected function add_frontend_scripts() {
            wp_register_script("comeet_script", ($this->plugin_url . 'js/comeet.js'));
            $options = $this->get_options();
            $post = get_post($options['post_id']);
            //echo $post->post_name;
            $comeet_thankyou_url = site_url() . '/' . $post->post_name . '/thankyou';
            $data = array("comeet_token" => $options['comeet_token'], "comeet_uid" => $options['comeet_uid'], "comeet_color" => $options['comeet_color'], "comeet_bgcolor" => $options['comeet_bgcolor'], "comeet_thankyou_url" => $comeet_thankyou_url);
            wp_localize_script("comeet_script", "comeetvar", $data);
            wp_enqueue_script("comeet_script");

        }

        protected function add_frontend_css() {
            $options = $this->get_options();
            $css_url = 'css/' . $options['comeet_stylesheet'];
            wp_enqueue_style('comeet_style', $this->plugin_url . $css_url, null, null, 'all');
        }


        function filter_title_simple($title) {
            if (isset($this->title)) {
                return $this->title;
            } else {
                return $title;
            }
        }

        function filter_title($title) {
            if (isset($this->title)) {
                return ["title" => $this->title];
            } else {
                return $title;
            }
        }


        function get_current_url() {
            $url = is_ssl() ? "https://" : "http://";
            $url .= $_SERVER["HTTP_HOST"];
            $url .= $_SERVER["REQUEST_URI"];
            return $url;
        }

        function filter_url($url) {
            if (isset($_SERVER["HTTP_HOST"])) {
                $url = $this->get_current_url();

            }
            return $url;
        }

        function filter_og_title($tag) {
            return $this->socialGraphTitle;

        }

        function comeet_add_template() {

            $comeet_cat = null;
            global $wp_query;
            $options = $this->get_options();
            if (isset($this->comeet_pos)) {
                $post_data = $this->post_data;
                $template = 'comeet-position-page.php';
            } else if (isset($wp_query->query_vars['comeet_cat'])) {
                if ($this->comeet_cat == 'thankyou') {
                    $template = 'comeet-thankyou-page.php';
                } else {
                    list($comeetgroups, $data, $group_element) = ComeetData::get_groups($options, $this->comeet_cat);
                    $comeet_cat = $this->comeet_cat;
                    $comeet_group = $options['advanced_search'];
                    $template = 'comeet-sub-page.php';
                }
            } else {
                list($comeetgroups, $data, $group_element) = ComeetData::get_groups($options, $this->comeet_cat);
                $comeet_group = $options['advanced_search'];
                $template = 'comeet-careers.php';
            }

            if (file_exists(get_template_directory() . '/' . $template)) {
                $template = get_template_directory() . '/' . $template;
            } elseif (file_exists($this->plugin_dir . 'templates/' . $template)) {
                $template = $this->plugin_dir . 'templates/' . $template;
            } else {
                echo '<div class="error">Error: Can not render page &ndash; no template found.</div>';
                die();
            }

            ob_start();
            include_once($template);
            $output = ob_get_contents();
            ob_end_clean();
            return $output;
        }

        function comeet_add_template_custom_shortcode($attr, $content) {
            $options = $this->get_options();
            global $wp_query;
            if (isset($wp_query->query_vars['comeet_pos'])) {
                $comeet_pos = urldecode($wp_query->query_vars['comeet_pos']);
            }
            if (isset($attr['name'])) {
                $comeet_cat = strtolower(preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '-', $attr['name'])));
            }
            //location is 0 and department is 1. Default is location
            if (!isset($attr['type'])) {
                $comeet_group = 0;
            } else if ($attr['type'] == 'department') {
                $comeet_group = 1;
            } elseif ($attr['type'] == 'location') {
                $comeet_group = 0;
            }

            if (isset($comeet_pos)) {
                $template = 'comeet-position-page-custom.php';
            } elseif (isset($comeet_cat)) {
                if ($comeet_cat == 'thankyou') {
                    $template = 'comeet-thankyou-page.php';
                } else {
                    $template = 'comeet-sub-page-custom.php';
                }
            } else {
                $template = 'blank.php';
            }

            //if(isset($comeet_group))
            //set the template file
            if (file_exists(get_template_directory() . '/' . $template)) {
                $template = get_template_directory() . '/' . $template;
            } elseif (file_exists($this->plugin_dir . 'templates/' . $template)) {
                $template = $this->plugin_dir . 'templates/' . $template;
            } else {
                echo '<div class="error">Error: Can not render page &ndash; no template found.</div>';
                die();
            }

            //set the output
            ob_start();
            include_once($template);
            $output = ob_get_contents();
            ob_end_clean();
            return $output;
        }

    } //  End class

    $Comeet = new Comeet();

    if ($Comeet) {
        register_activation_hook(__FILE__, array(&$Comeet, 'install'));

        function comeet_add_query_vars($aVars) {
            $aVars[] = "comeet_cat"; // represents the name of the department or location in URL
            $aVars[] = "comeet_pos"; // represents the name of the position as shown in URL
            return $aVars;
        }

        // hook add_query_vars function into query_vars
        add_filter('query_vars', 'comeet_add_query_vars');

    }
}


?>
