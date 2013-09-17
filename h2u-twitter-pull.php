<?php
/*
Plugin Name: Twitter Pull
Plugin URI: http://hwa2u.com/
Description: This plugin will check Twitter every 10 minutes for any new tweets, if there's then the plugin will pull them all and post them as normal WordPress blog post. Several options available such as define the category for the post as well as not to post twitter mentions.
Author: hwa
Author URI: http://hwa2u.com/
Version: 1.1.1

For more information, please look at README.md.
*/

// stop direct call
if (preg_match('#' . basename(__FILE__) . '#', $_SERVER['PHP_SELF'])) {
	die('You are not allowed to call this page directly.');
}

if (!class_exists('h2u_twitter_pull')) {
	// load Twitter OAuth API
	require_once('twitteroauth/twitteroauth.php');
	require_once('h2u-twitter-pull-cron.php');

	class h2u_twitter_pull {
		var $version;
		var $plugin_name;
		var $plugin_file_name;
		var $plugin_file_url;

		var $options = array();

		// constructor
		function __construct() {
			$this->version = '1.1.1';
			$this->plugin_name = plugin_basename(__FILE__);
			$this->plugin_file_name = basename(__FILE__);
			$this->plugin_file_url = admin_url('options-general.php?page='.$this->plugin_file_name);

			// options with default value
			$this->options = array(
				'user_id' => '',
				'screen_name' => '',
				'consumer_key' => '',
				'consumer_secret' => '',
				'access_token' => '',
				'access_token_secret' => '',
				'last_tweet_id' => '',
				'exclude_replies' => '1',
				'include_rts' => '1',
				'hastag_as_tag' => '1',
				'post_author' => '1',	// 1 is the id for the blog super admin
				'post_title' => 'Tweeted By %username% On %date% - %content%',
				'post_category' => array(),
				'post_tags' => array(),
				'set_post_format' => '1',
				'img_dir' => 'twitter_img',
				'img_width' => 380,
				'str_to_filter' => array()
			);

			// populate option values from database
			$this->get_options();
		}

		function request_oauth() {
			// create OAuth object
			$connection = $this->create_twitter_connection(trim($_POST['h2utp_consumer_key']), trim($_POST['h2utp_consumer_secret']));
			if (!$connection) {
				// TODO: report user with error message
				// request temporally OAuth failed, reset everything
				$this->disconnect_twitter();
				wp_redirect($this->plugin_file_url);
				exit;
			} else {
				// request temporally tokens
				$req_token = $connection->getRequestToken($this->plugin_file_url);
				if (isset($req_token['oauth_token'])) {
					// save consumer key and consumer secret into database, we need this later
					$this->options['consumer_key'] = trim($_POST['h2utp_consumer_key']);
					$this->options['consumer_secret'] = trim($_POST['h2utp_consumer_secret']);
					// save the options
					$this->save_options();

					// save temporally OAuth tokens into session
					$temp_token = $req_token['oauth_token'];
					session_start();
					$_SESSION['oauth_token'] = $temp_token;
					$_SESSION['oauth_token_secret'] = $req_token['oauth_token_secret'];

					// bring user to the Twitter application authorization page
					wp_redirect($connection->getAuthorizeURL($temp_token));
					exit;
				} else {
					// TODO: report user with error message
					// request temporally OAuth failed, reset everything
					$this->disconnect_twitter();
					wp_redirect($this->plugin_file_url);
					exit;
				}
			}
		}

		function confirm_oauth($oauth_token, $oauth_verifier) {
			// get the consumer key and consumer secret we saved earlier
			$consumer_key = $this->options['consumer_key'];
			$consumer_secret = $this->options['consumer_secret'];

			// for security reason, we assign the sessions to local variable and quickly unset them
			session_start();
			$old_oauth_token = $_SESSION['oauth_token'];
			$old_oauth_token_secret = $_SESSION['oauth_token_secret'];
			unset($_SESSION['oauth_token']);
			unset($_SESSION['oauth_token_secret']);
			session_destroy();
			if ($old_oauth_token == $oauth_token) {
				// create OAuth object from previous request
				$connection = $this->create_twitter_connection($consumer_key, $consumer_secret, $old_oauth_token, $old_oauth_token_secret);

				// request access tokens
				$access_token = $connection->getAccessToken($oauth_verifier);
				if (isset($access_token)) {
					// we successfully create OAuth connection to Twitter, populate all options then save it
					$this->options['user_id'] = $access_token['user_id'];
					$this->options['screen_name'] = $access_token['screen_name'];
					$this->options['consumer_key'] = $consumer_key;
					$this->options['consumer_secret'] = $consumer_secret;
					$this->options['access_token'] = $access_token['oauth_token'];
					$this->options['access_token_secret'] = $access_token['oauth_token_secret'];

					// save the options
					$this->save_options();

					// just to get the Setting saved message, by right this is not necessary
					wp_redirect($this->plugin_file_url.'&updated=true');
					exit;
				} else {
					// TODO: report user with error message
					// request access token failed, remove the temporally consumer key and consumer secret in database
					$this->disconnect_twitter();
					wp_redirect($this->plugin_file_url);
					exit;
				}
			} else {
				// TODO: report user with error message
				// session expired, remove the temporally consumer key and consumer secret we set just now
				$this->disconnect_twitter();
			}
		}

		function create_twitter_connection($consumer_key=null, $consumer_secret=null, $access_token=null, $access_token_secret=null) {
			$connection = null;
			if (!$this->has_value($consumer_key) || !$this->has_value($consumer_secret)) {
				// no parameter passed, so use the setting in database, but check first whether Twitter is configured.
				if ($this->twitter_is_set()) {
					$connection = new TwitterOAuth($this->options['consumer_key'], $this->options['consumer_secret'], $this->options['access_token'], $this->options['access_token_secret']);
				} else {
					return false;
				}
			} else if ($this->has_value($consumer_key) && $this->has_value($consumer_secret) && $this->has_value($access_token) && $this->has_value($access_token_secret)) {
				// all parameter is passed in, should be called when confirming OAuth
				$connection = new TwitterOAuth($consumer_key, $consumer_secret, $access_token, $access_token_secret);
			} else if ($this->has_value($consumer_key) && $this->has_value($consumer_secret) && !$this->has_value($access_token) && !$this->has_value($access_token_secret)) {
				// only have consumer key and consumer secret, should be called when creating OAuth
				$connection = new TwitterOAuth($consumer_key, $consumer_secret);
			} else {
				// whatever funny combination of parameter passed in goes here
				return false;
			}
			$connection->useragent = 'Twitter Pull http://hwa2u.com';

			return $connection;
		}

		function twitter_is_set() {
			if ($this->has_value($this->options['consumer_key']) &&
				$this->has_value($this->options['consumer_secret']) &&
				$this->has_value($this->options['access_token']) &&
				$this->has_value($this->options['access_token_secret'])) {
				return true;
			} else {
				return false;
			}
		}

		function alert_configure_twitter() {
			if (!$this->twitter_is_set()) {
				echo '<div class="error"><p>Please configure your Twitter account at <a href="'.$this->plugin_file_url.'">Twitter Pull</a> option page.</p></div>';
			}
		}

		function disconnect_twitter() {
			if (current_user_can('manage_options')) {
				// remove Twitter options
				$this->options['user_id'] = '';
				$this->options['screen_name'] = '';
				$this->options['consumer_key'] = '';
				$this->options['consumer_secret'] = '';
				$this->options['access_token'] = '';
				$this->options['access_token_secret'] = '';

				// clear Twitter pull event
				wp_clear_scheduled_hook('twitter_pull_event');

				// save the options
				$this->save_options();
			}
		}

		 function cron_add_10_minutes($schedules) {
			// adds every 10 minutes to the existing schedules.
			$schedules['10minutes'] = array(
				'interval' => 600,
				'display' => __( 'Once 10 Minutes' )
			);
			return $schedules;
		 }

		function schedule_twitter_pull_event() {
			if ($this->twitter_is_set()) {
				add_filter('cron_schedules', array(&$this, 'cron_add_10_minutes'));
				if (!wp_next_scheduled('schedule_twitter_pull')) {
					wp_schedule_event(time(), '10minutes', 'schedule_twitter_pull');
				}
				add_action('schedule_twitter_pull', array(&$this, 'run_twitter_pull'), 10, 1);
			}
		}

		function run_twitter_pull() {
			$h2utp_cron = new h2u_twitter_pull_cron($this->options);
			$h2utp_cron->twitter_pull();
		}

		function activate() {
			// setup database with default value
			add_option('h2utp_options', json_encode($this->options));
		}

		function deactivate() {
			// clear Twitter pull event
			wp_clear_scheduled_hook('twitter_pull_event');
			// remove the options
			delete_option('h2utp_options');
		}

		function save_options() {
			if (current_user_can('manage_options')) {
				update_option('h2utp_options', json_encode($this->options));
			}
		}

		function get_options() {
			if ($option_str = json_decode(get_option('h2utp_options'), true)) {
				foreach ($option_str as $k => $v) {
					$this->options[$k] = $v;
				}
				return $this->options;
			} else {
				return false;
			}
		}

		function set_options($options) {
			if (current_user_can('manage_options')) {
				// process tags
				if (isset($options['post_tags'])) {
					$post_tags = array_filter(explode("\n", $this->remove_empty_lines(stripslashes($options['post_tags']))));
					$options['post_tags'] = array_map('trim', $post_tags);
				}

				// process string to filter
				if (isset($options['str_to_filter'])) {
					$str_to_filter = array_filter(explode("\n", $this->remove_empty_lines(stripslashes($options['str_to_filter']))));
					$options['str_to_filter'] = array_map('trim', $str_to_filter);
				}

				// set the option value here
				foreach ($options as $k => $v) {
					if (array_key_exists($k, $this->options)) {
						$this->options[$k] = $v;
					}
				}
				// save the options
				$this->save_options();
			}
		}

		function options_form() {
			print '
				<style type="text/css" media="screen">
				#h2utp_wrap {
					font-size: 1em;
				}
				#h2utp_wrap .option {
					overflow: hidden;
					padding-bottom: 9px;
					padding-top: 9px;
				}
				#h2utp_wrap .option label {
					display: block;
					float: left;
					width: 200px;
					margin-right: 24px;
					text-align: right;
				}
				#h2utp_wrap .option input, #h2utp_wrap .option select, #h2utp_wrap .option textarea {
					display: block;
					margin: 0 0 10px 250px;
				}
				#h2utp_wrap .option span {
					color: #666666;
					margin-left: 255px;
					font-size: 0.9em;
				}
				#h2utp_wrap .option #post_input_checkboxes {
					width: 500px;
					display: block;
					margin: 0 0 10px 250px;
					overflow:hidden;
				}
				#h2utp_wrap .option #post_input_checkboxes label {
					display: block;
					margin: 0;
					text-align: left;
					clear: both;
				}
				#h2utp_wrap .option #post_input_checkboxes input {
					display: inline;
					margin: 0 10px 5px 0;
				}
				#h2utp_twitter_form_display {
					display: none;
				}
				#h2utp_twitter_form_display .option span {
					margin: 0;
				}
				#h2utp_twitter_form_display .option span.info_label {
					width: 200px;
					display: block;
					float: left;
					padding: 0 20px;
					text-align: right;
					font-size: 1em;
					font-style: normal;
				}
				#h2utp_twitter_form_display .option span.info_value {
					width: 500px;
					display: block;
					float: left;
					padding: 0 20px;
					text-align: left;
					font-size: 1em;
					font-style: normal;
				}
				</style>

				<script type="text/javascript">
				jQuery(function($) {
					$("#h2utp_twitter_from_showhide").click(function(){
						$("#h2utp_twitter_form_display").slideToggle();
					});
				});
				</script>';

			// get all user id and name as array for post author option (option id is post_author)
			$all_user_id = array();
			$all_user_name = array();
			foreach (get_users() as $k => $v) {
				array_push($all_user_id, $v->ID);
				array_push($all_user_name, $v->display_name);
			}

			// get all categories and put them as HTML checkboxes (option id is post_category)
			$catogory_checkbox = '<div id="post_input_checkboxes">';
			foreach (get_categories() as $k => $v) {
				$checked = '';
				if (in_array($v->term_id, $this->options['post_category'])) $checked = ' checked';
				$catogory_checkbox .= '<label><input type="checkbox" name="post_category[]" value="'.$v->term_id.'"'.$checked.' />'.$v->name.'</label>';
			}
			$catogory_checkbox .= '</div>';

			// get all tags from database and arrange each of them in new line
			$post_tags = '';
			if (!empty($this->options['post_tags'])) {
				foreach ($this->options['post_tags'] as $value) {
					$post_tags .= $value."\n";
				}
			}

			// get all filter string from database and arrange each of them in new line
			$str_to_filter = '';
			if (!empty($this->options['str_to_filter'])) {
				foreach ($this->options['str_to_filter'] as $value) {
					$str_to_filter .= $value."\n";
				}
			}

			print '<div class="wrap">';

			// check to see if Twitter OAuth is set
			if ($this->twitter_is_set()) {
				// twitter OAuth option form
				print '<h2>'.__('Twitter Pull Options', 'h2utp').'</h2>
						<p><a href="#" id="h2utp_twitter_from_showhide">Account Information</a></p>
						<div id="h2utp_twitter_form_display">
							<div id="h2utp_wrap">
								<form id="h2utp_disconnect_twitter_form" action="'.admin_url('options-general.php').'" method="post">
									<fieldset class="options">
										<div class="option"><span class="info_label">'.__('Twitter Username', 'h2utp').'</span><span class="info_value">'.$this->options['screen_name'].'</span></div>
										<div class="option"><span class="info_label">'.__('Consumer Key', 'h2utp').'</span><span class="info_value">'.$this->options['consumer_key'].'</span></div>
										<div class="option"><span class="info_label">'.__('Consumer Secret', 'h2utp').'</span><span class="info_value">'.$this->options['consumer_secret'].'</span></div>
										<div class="option"><span class="info_label">'.__('Access Token', 'h2utp').'</span><span class="info_value">'.$this->options['access_token'].'</span></div>
										<div class="option"><span class="info_label">'.__('Access Token Secret', 'h2utp').'</span><span class="info_value">'.$this->options['access_token_secret'].'</span></div>
									</fieldset>
									<p class="submit"><input type="submit" name="submit" value="'.__('Disconnect From Twitter', 'h2utp').'" class="button-primary" /></p>
									<p><input type="hidden" name="h2u_action" value="h2utp_disconnect_twitter" />'.wp_nonce_field('h2utp_disconnect_twitter', '_wpnonce', true, false).wp_referer_field(false).'</p>
								</form>
							</div>
						</div>

						<div id="h2utp_wrap">
							<form id="h2utp_option_form" action="'.admin_url('options-general.php').'" method="post">
								<fieldset class="options">
									<div class="option">
										<label for="exclude_replies">'.__('Exclude Replies:', 'h2utp').'</label>
										'.$this->create_select_options('<select name="exclude_replies" id="exclude_replies">', '</select>', array('1', '0'), array('Yes', 'No'), $this->options['exclude_replies']).'
										<span>'.__('Exclude replies to be pulled from Twitter.', 'h2utp').'</span>
									</div>
									<div class="option">
										<label for="include_rts">'.__('Include Retweets:', 'h2utp').'</label>
										'.$this->create_select_options('<select name="include_rts" id="include_rts">', '</select>', array('1', '0'), array('Yes', 'No'), $this->options['include_rts']).'
										<span>'.__('Retweets will be pulled from Twitter.', 'h2utp').'</span>
									</div>
									<div class="option">
										<label for="hastag_as_tag">'.__('Include Hashtags As Tags:', 'h2utp').'</label>
										'.$this->create_select_options('<select name="hastag_as_tag" id="hastag_as_tag">', '</select>', array('1', '0'), array('Yes', 'No'), $this->options['hastag_as_tag']).'
										<span>'.__('Include Twitter hashtags as post tags.', 'h2utp').'</span>
									</div>
									<div class="option">
										<label for="set_post_format">'.__('Set Post Format:', 'h2utp').'</label>
										'.$this->create_select_options('<select name="set_post_format" id="set_post_format">', '</select>', array('1', '0'), array('Yes', 'No'), $this->options['set_post_format']).'
										<span>'.__('Enabling this will set the format of the post. for more information please check out <a href="http://codex.wordpress.org/Post_Formats">Post Formats</a>.', 'h2utp').'</span>
									</div>
									<div class="option">
										<label for="post_author">'.__('Post Author:', 'h2utp').'</label>
										'.$this->create_select_options('<select name="post_author" id="post_author">', '</select>', $all_user_id, $all_user_name, $this->options['post_author']).'
										<span>'.__('Local author for Twitter posts.', 'h2utp').'</span>
									</div>
									<div class="option">
										<label for="post_title">'.__('Twitter Post Title:', 'h2utp').'</label>
										<input type="text" size="30" name="post_title" id="post_title" value="'.$this->options['post_title'].'" />
										<span>'.__('<b>%username%</b> - Twitter username.', 'h2utp').'</span><br />
										<span>'.__('<b>%date%</b> - Tweeted date.', 'h2utp').'</span><br />
										<span>'.__('<b>%content%</b> - The Tweet, will truncated after 30 characters.</b>', 'h2utp').'</span>
									</div>
									<div class="option">
										<label for="post_category">'.__('Post Category:', 'h2utp').'</label>
										'.$catogory_checkbox.'
										<span>'.__('Categories for Twitter posts.', 'h2utp').'</span>
									</div>
									<div class="option">
										<label for="post_tags">'.__('Twitter Post Tags:', 'h2utp').'</label>
										<textarea name="post_tags" id="post_tags" rows="5" cols="50">'.$post_tags.'</textarea>
										<span>'.__('Tags for Twitter posts. Separate each tag with newline.', 'h2utp').'</span>
									</div>
									<div class="option">
										<label for="str_to_filter">'.__('Words To Exclude From Post:', 'h2utp').'</label>
										<textarea name="str_to_filter" id="str_to_filter" rows="5" cols="50">'.$str_to_filter.'</textarea>
										<span>'.__('When the Tweet contain these words, Twitter Pull will not create the post. Separate each word with newline.', 'h2utp').'</span>
									</div>
									<div class="option">
										<label for="img_dir">'.__('Image Directory:', 'h2utp').'</label>
										<input type="text" size="30" name="img_dir" id="img_dir" value="'.$this->options['img_dir'].'" />
										<span>'.__('Folder to save the images downloaded from Twitter. The folder will be created in WP upload folder.', 'h2utp').'</span>
									</div>
									<div class="option">
										<label for="img_width">'.__('Image Width:', 'h2utp').'</label>
										<input type="text" size="30" name="img_width" id="img_width" value="'.$this->options['img_width'].'" />
										<span>'.__('Maximum image width to show in post, if the original image width is larger than this value, Twitter Pull will create a thumbnail and link to original image.', 'h2utp').'</span>
									</div>
								</fieldset>
								<p class="submit"><input type="submit" name="submit" value="'.__('Save Settings', 'h2utp').'" class="button-primary" /></p>
								<p><input type="hidden" name="h2u_action" value="h2utp_option" />'.wp_nonce_field('h2utp_option', '_wpnonce', true, false).wp_referer_field(false).'</p>
							</form>
						</div>';
			} else {
				print '<h2>'.__('Twitter Configurations', 'h2utp').'</h2>
						<div id="h2utp_wrap">
							<form id="h2utp_connect_twitter_form" action="'.admin_url('options-general.php').'" method="post">
								<fieldset class="options">
									<div class="option">
										<label for="h2utp_consumer_key">'.__('Consumer Key:', 'h2utp').'</label>
										<input type="text" size="30" name="h2utp_consumer_key" id="h2utp_consumer_key" value="'.$this->options['consumer_key'].'" />
										<span>'.__('Twitter consumer key.', 'h2utp').'</span>
									</div>
									<div class="option">
										<label for="h2utp_consumer_secret">'.__('Consumer Secret:', 'h2utp').'</label>
										<input type="text" size="30" name="h2utp_consumer_secret" id="h2utp_consumer_secret" value="'.$this->options['consumer_secret'].'" />
										<span>'.__('Twitter consumer secret.', 'h2utp').'</span>
									</div>
								</fieldset>
								<p><input type="image" name="submit" src="'.plugin_dir_url(__FILE__).'images/signin.png" value="'.__('Save Settings', 'h2utp').'" /></p>
								<p><input type="hidden" name="h2u_action" value="h2utp_connect_twitter" />'.wp_nonce_field('h2utp_connect_twitter', '_wpnonce', true, false).wp_referer_field(false).'</p>
							<form>
						</div>

						<h2>'.__('Connect To Twitter', 'h2utp').'</h2>
						<div id="h2utp_wrap">
							<p>In order for you to connect to Twitter you will need <b>Consumer Key</b> and <b>Consumer Secret</b>, which you can generate by registering a <b>Twitter Application</b>. If you already have one please insert it into the form above.</p>
							<p>Otherwise, you can follow these simple steps to register a new Twitter application.</p>
							<ol>
								<li>Go to <a target="_blank" href="https://dev.twitter.com/apps/new">Twitter: Create an application</a> and login using your Twitter account.</li>
								<li>Fill in the form, following fields are <b>mandatory</b>:
									<ul>
										<li><b>Name</b> - Could be any name you like. Suggestion: You may use <i>'.get_bloginfo('name').'</i> as the application name, as long as it doesn\'t contain the word <i>Twitter</i>.</li>
										<li><b>Description</b> - Brief description of the application. Suggestion: Allow <i>WordPress Twitter Pull</i> plugin to pull tweets from Twitter.</li>
										<li><b>Website</b> - Website to the Twitter application. Suggestion: You may use <i>'.get_bloginfo('url').'</i>, remember to include <i>http://</i>.</li>
										<li><b>Callback URL</b> - Any valid URL. Suggestion: You may use <i>'.get_bloginfo('url').'</i>, remember to include <i>http://</i>.</li>
									</ul>
								</li>
								<li>Make sure you finish read the <b>Developer Rules Of The Road</b> and <b>agree</b> with it.</li>
								<li>Fill in the <b>CAPTCHA</b> and click <b>Create your Twitter application</b></li>
							</ol>
							<p>Next you should be able to see the <b>Consumer Key</b> and <b>Consumer Secret</b>, copy and paste both of them into the form above.
						</div>';
			}

			print '</div>';
		}

		function request_handler() {
			if (!empty($_POST['h2u_action'])) {
				switch ($_POST['h2u_action']) {
					case 'h2utp_option':
						if (!wp_verify_nonce($_POST['_wpnonce'], 'h2utp_option')) {
							wp_die('Oops, please try again.');
						}
						$this->set_options($_POST);
						wp_redirect($this->plugin_file_url.'&updated=true');
						die();
						break;
					case 'h2utp_disconnect_twitter':
						if (!wp_verify_nonce($_POST['_wpnonce'], 'h2utp_disconnect_twitter')) {
							wp_die('Oops, please try again.');
						}
						$this->disconnect_twitter();
						wp_redirect($this->plugin_file_url.'&updated=true');
						die();
						break;
					case 'h2utp_connect_twitter':
						if (!wp_verify_nonce($_POST['_wpnonce'], 'h2utp_connect_twitter')) {
							wp_die('Oops, please try again.');
						}
						$this->request_oauth();
						die();
						break;
				}
			}

			if (!empty($_GET['oauth_token']) && !empty($_GET['oauth_verifier'])) {
				$this->confirm_oauth($_GET['oauth_token'], $_GET['oauth_verifier']);
			}
		}

		function create_select_options($prefix, $suffix, $values=array(), $options=array(), $selected_value) {
			if (count($values) != count($options)) {
				return false;
			} else {
				$output = '';
				foreach ($values as $k => $v) {
					$selected = '';
					if ($selected_value == $v) $selected = ' selected';
					$output .= '<option value="'.$v.'"'.$selected.'>'.$options[$k].'</option>';
				}
			}
			return $prefix.$output.$suffix;
		}

		// remove any empty lines from text box
		function remove_empty_lines($str) {
			return preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $str);
		}

		function has_value($var) {
			if (isset($var) && trim($var) != '') {
				return true;
			} else {
				return false;
			}
		}

		function print_pre($var) {
			echo '<pre>';
			print_r($var);
			echo '</pre>';
		}
	}

	function h2utp_add_options_page() {
		global $h2utp;
		add_options_page(__('Twitter Pull Options', 'h2utp'), __('Twitter Pull', 'h2utp'), 'manage_options', basename(__FILE__), array(&$h2utp, 'options_form'));
	}

	$h2utp = new h2u_twitter_pull();
	// adding hooks and filters
	if (isset($h2utp)) {
		if (is_admin()) {
			register_activation_hook($h2utp->plugin_name, array(&$h2utp, 'activate'));
			register_deactivation_hook($h2utp->plugin_name, array(&$h2utp, 'deactivate'));
			add_action('init', array(&$h2utp, 'request_handler'), 10, 1);
			add_action('admin_notices', array(&$h2utp, 'alert_configure_twitter'), 10, 1);
			add_action('admin_menu', 'h2utp_add_options_page', 10, 1);
		}
		$h2utp->schedule_twitter_pull_event();
	}
}
//ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);
?>
