<?php
// stop direct call
if (preg_match('#' . basename(__FILE__) . '#', $_SERVER['PHP_SELF'])) {
	die('You are not allowed to call this page directly.');
}

if (!class_exists('h2u_twitter_pull_cron')) {
	// load Twitter OAuth API
	require_once('twitteroauth/twitteroauth.php');

	class h2u_twitter_pull_cron {
		var $request_url;
		var $connection;
		var $post_format;
		
		// option array
		var $options = array();
		
		// constructor
		function __construct($options) {
			// stop if Twitter has not been setup
			if (!$this->has_value($options['consumer_key']) || 
				!$this->has_value($options['consumer_secret']) || 
				!$this->has_value($options['access_token']) || 
				!$this->has_value($options['access_token_secret'])) {
				return;
			}
			
			define('USER_AGENT', 'Twitter Pull http://hwa2u.com');
			define('PROFILE_URL', 'http://twitter.com');
			define('HASHTAG_URL', 'http://search.twitter.com/search?q=');
			
			// options retrieved from database
			$this->options = $options;
			
			$this->request_url = 'https://api.twitter.com/1.1/statuses/user_timeline.json?screen_name='.$this->options['screen_name'].'&include_entities=true&trim_user=true';
			// create twitter object
			$this->connection = new TwitterOAuth($this->options['consumer_key'], $this->options['consumer_secret'], $this->options['access_token'], $this->options['access_token_secret']);
			$this->connection->useragent = USER_AGENT;
			$this->connection->format = 'xml';
		}
		
		function twitter_pull() {
			global $wpdb;
			
			// check to see if this is the first request to Twitter, if it's then only retrieve the latest tweet
			$str_append = '&count=1';
			if ($this->options['last_tweet_id']) {
				$str_append = '&since_id='.$this->options['last_tweet_id'];
			}
			if ($this->options['exclude_replies']) $str_append .= '&exclude_replies=true';	// exclude replies
			if ($this->options['include_rts']) $str_append .= '&include_rts=true';			// include retweets
			
			// do request to Twitter
			$tweets = json_decode($this->connection->get($this->request_url.$str_append), true);
			
			// if the request return an array
			if (is_array($tweets)) {
				$tweets = array_reverse($tweets);	// latest first
				foreach ($tweets as $tweet) {
                    // we gonna use this a lot, so save it as local variable
                    $tweet_text = $tweet['text'];
                    
                    // if nothing in the text, proceed to next tweet
                    if (!$this->has_value($tweet_text)) continue;
                    
                    // save last tweet id
					$this->options['last_tweet_id'] = $tweet['id_str'];
                    if (!$this->has_value($this->options['last_tweet_id'])) {
                        $this->options['last_tweet_id'] = '';
                    }
					$this->post_format = 'post-format-status';
					
					// combine hashtags with predefined tags
					$all_tags = $this->options['post_tags'];
					if ($this->options['hastag_as_tag'] && preg_match_all('/#(\\w+)/', $tweet_text, $hashtags)) {
						foreach ($hashtags[1] as $hashtag) {
							$all_tags[] = $hashtag;
						}
						$all_tags = array_unique($all_tags);
					}
					
					// process everything under entities nodes, url, mentions, hashtags, media and etc then only save them as wp post
					$tweet_text = $this->filter_url($tweet_text, $tweet['entities']['urls']);		// filter url
					$tweet_text = $this->filter_mention($tweet_text);								// filter mentions
					$tweet_text = $this->filter_hastag($tweet_text);								// filter hastags
					$tweet_text = $this->filter_image($tweet_text, $tweet['entities']['media']);	// filter images
					$tweet_text = make_clickable($tweet_text);
					
					// tweets to be excluded from creating a post
					$skip_this = false;
					if ($str_to_filter = $this->options['str_to_filter']) {
						foreach ($str_to_filter as $str) {
							if (stripos(strip_tags($tweet_text), $str) !== false) {
								$skip_this = true;
								break;
							}
						}
						unset ($str);
					}
					if ($skip_this) continue;
					
					// process the title
					$tweet_title = '';
					if (!$this->has_value($this->options['post_title'])) {
						$tweet_title = $this->trim_wrap_with_ellipsis($tweet['text'], 30);
					} else {
						$search = array('%username%', '%date%', '%content%');
						$replace = array($this->options['screen_name'], $this->twitter_format_date('d M Y', $tweet['created_at']), $this->trim_wrap_with_ellipsis($tweet['text'], 30));
						$tweet_title = str_replace($search, $replace, $this->options['post_title']);
					}
					
					// creating the post array
					$post = array(
						'post_author' => $wpdb->escape($this->options['post_author']),
						'post_title' => $wpdb->escape($tweet_title),
						'post_content' => $wpdb->escape($tweet_text),
						'post_date' => $this->twitter_format_date('Y-m-d H:i:s', $tweet['created_at']),
						'post_status' => 'publish'
					);
					if (!$post) return false;
					
					// creating the post
					$post_id = wp_insert_post($post);
					if ($post_id) {
						if (!empty($this->options['post_category'])) wp_set_post_terms($post_id, $this->options['post_category'], 'category');
						if (!empty($all_tags)) wp_set_post_tags($post_id, $all_tags);
						if ($this->options['set_post_format']) wp_set_object_terms($post_id, $this->post_format, 'post_format');
					}
				}
				// save the options
				$this->save_options();
			} else {
				// request failed
				return false;
			}
		}
		
		function filter_url($tweet, $urls) {
			$tweet = preg_replace('/((http(s?):\/\/)|(www\.))([\w\.]+)([a-zA-Z0-9?&%.;:\/=+_-]+)/i', '<a href="http$3://$4$5$6">$2$4$5$6</a>', $tweet);
			foreach ($urls as $url) {
				$tweet = str_replace('>'.$url['url'].'<', '>'.$url['display_url'].'<', $tweet);
			}
			return $tweet;
		}
		
		function filter_mention($tweet) {
			return preg_replace('/(?<=\A|[^A-Za-z0-9_])@([A-Za-z0-9_]+)(?=\Z|[^A-Za-z0-9_])/', '<a href="'.PROFILE_URL.'/$1">$0</a>', $tweet);
		}
		
		function filter_hastag($tweet) {
			return preg_replace('/(?<=\A|[^A-Za-z0-9_])#([A-Za-z0-9_]+)(?=\Z|[^A-Za-z0-9_])/', '<a href="'.HASHTAG_URL.'%23$1">$0</a>', $tweet);
		}
		
		function filter_image($tweet, $medias) {
			if (is_array($medias)) {
				foreach ($medias as $media) {
					if ($media['type'] == 'photo' && $this->has_value($media['media_url'])) {
						$tweet_img_file = $media['media_url'];
						$upload_dir = wp_upload_dir();
						$img_dir = $this->options['img_dir'];
						
						// download the image
						if ($this->download_media($tweet_img_file, $img_dir, ':large')) {
							$img_width = $media['sizes']['large']['w'];
							
							// if the image width is smaller than $this->options['img_width'], then there should be no external link to the image
							if ($img_width < $this->options['img_width']) {
								$tweet .= '<p><div class="h2u_tweet_img"><img src="'.$upload_dir['baseurl'].'/'.$img_dir.'/'.basename($tweet_img_file).'" /></div></p>';
							} else {
								// create thumbnail of the image
								$tweet_thumb_filename = $this->create_thumbnail($upload_dir['basedir'].'/'.$img_dir.'/'.basename($tweet_img_file), $this->options['img_width']);
								if (!$this->has_value($tweet_thumb_filename)) $tweet_thumb_filename = $upload_dir['baseurl'].'/'.$img_dir.'/'.basename($tweet_img_file);	// thumbnail failed to create
								$tweet .= '<div class="h2u_tweet_img"><a href="'.$upload_dir['baseurl'].'/'.$img_dir.'/'.basename($tweet_img_file).'"><img src="'.$upload_dir['baseurl'].'/'.$img_dir.'/'.$tweet_thumb_filename.'" width="'.$this->options['img_width'].'" /></a></div>';
							}
						}
					}
				}
				$this->post_format = 'post-format-image';
			}
			return $tweet;
		}
		
		// download image and store in a specified location
		function download_media($img_url, $img_dir, $img_param='') {
			$img_ext = 'jpg|jpeg|gif|png';	// define the filename extensions you're allowing

			// check against a regexp for an actual http url and for a valid filename, also extract that filename using a submatch (see PHP's regexp docs to understand this)
			if (preg_match('#^http://.*([^/]+\.('.$img_ext.'))$#', $img_url)) {
				// getting the file name
				$file_name = basename($img_url);
				
				// getting wordpress upload folder
				$upload_dir = wp_upload_dir();
				$img_path = $upload_dir['basedir'].'/'.$img_dir.'/';
				// if $img_dir not exist then create it
				if (!is_dir($img_path)) mkdir($img_path, 0775);
				
				// start fetching file
				$ch = curl_init($img_url.$img_param);
				$fp = fopen($img_path.'/'.$file_name, 'wb');
				curl_setopt($ch, CURLOPT_FILE, $fp);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_exec($ch);
				curl_close($ch);
				fclose($fp);
				
				// change the file permission
				chmod($img_path.'/'.$file_name, 0664);
				
				return true;
			} else {
				return false;
			}
		}
		
		// generate thumbnail from given image
		function create_thumbnail($img_path, $thumb_width) {
			$img_file = explode('.', basename($img_path));
			
			// only allow JPG, JPEG and PNG image files
			if ((file_exists($img_path)) && (preg_match('/jpg|jpeg|png/', strtolower($img_file[1])))) {
				$img_type_is_jpeg = false;
				
				// generate raw image
				if (preg_match('/jpg|jpeg/', strtolower($img_file[1]))) {
					$src_img = imagecreatefromjpeg($img_path);
					$img_type_is_jpeg = true;
				} else if (preg_match('/png/', strtolower($img_file[1]))) {
					$src_img = imagecreatefrompng($img_path);
				}
			
				$src_w = imageSX($src_img);
				$src_h = imageSY($src_img);
				
				// only calculate the thumbnail dimension when the original image is larger than thumbnail's width
				$thumb_w = 0;
				$thumb_h = 0;
				if ($src_w > $thumb_width) {
					$thumb_w = $thumb_width;
					//$thumb_h = floor($src_w*($thumb_width/$src_h));	// find the height of the thumbnail, relative to the width
					$thumb_h = round($src_h*($thumb_width/$src_w));
				} else {
					// no need resize
					$thumb_w = $src_w;
					$thumb_h = $src_h;
				}
			
				$dst_img = ImageCreateTrueColor($thumb_w, $thumb_h);
				imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $thumb_w, $thumb_h, $src_w, $src_h);
				
				// generate the thumbnail faile name including it's path
				$thumb_path = dirname($img_path).'/thumb_'.basename($img_path);
			
				if ($img_type_is_jpeg) {
					imagejpeg($dst_img, $thumb_path);
				} else {
					imagepng($dst_img, $thumb_path);
				}
				
				imagedestroy($dst_img);
				imagedestroy($src_img);
				
				return basename($thumb_path);
			} else {
				return false;
			}
		}
		
		// format Twitter time
		function twitter_format_date($format, $date) {
			return date($format, strtotime(get_date_from_gmt(date('Y-m-d H:i:s', strtotime($date)))));
		}
		
		function trim_wrap_with_ellipsis($str, $limit = 100) {
			if (strlen($str) > $limit) {
				if ($str[$limit] == ' ') {
					$str = substr($str, 0, $limit);
				} else {
					$wrapped_str = strrpos(substr($str, 0, $limit), ' ');
					if ($wrapped_str === false) {	// if the string has no space
						$str = substr($str, 0, $limit);
					} else {
						$str = substr($str, 0, $wrapped_str);
					}
				}
			}
			return trim($str).'&hellip;';
		}
		
		function save_options() {
			update_option('h2utp_options', json_encode($this->options));
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
}
?>
