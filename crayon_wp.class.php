<?php
/*
 Plugin Name: Crayon Syntax Highlighter
Plugin URI: http://ak.net84.net/projects/crayon-syntax-highlighter
Description: Supports multiple languages, themes, highlighting from a URL, local file or post text.
Version: 1.13
Author: Aram Kocharyan
Author URI: http://ak.net84.net/
Text Domain: crayon-syntax-highlighter
Domain Path: /trans/
License: GPL2
	Copyright 2012	Aram Kocharyan	(email : akarmenia@gmail.com)
	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
	
	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
require_once ('global.php');
require_once (CRAYON_HIGHLIGHTER_PHP);
require_once (CRAYON_TE_PHP);
require_once (CRAYON_THEME_EDITOR_PHP);
require_once ('crayon_settings_wp.class.php');

if (defined('ABSPATH')) {
	// Used to get plugin version info
	require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	crayon_set_info(get_plugin_data( __FILE__ ));
}

/* The plugin class that manages all other classes and integrates Crayon with WP */
class CrayonWP {
	// Properties and Constants ===============================================

	//	Associative array, keys are post IDs as strings and values are number of crayons parsed as ints
	private static $post_queue = array();
	// Ditto for comments
	private static $comment_queue = array();
	private static $post_captures = array();
	private static $comment_captures = array();
	// Whether we are displaying an excerpt
	private static $is_excerpt = FALSE;
	// Whether we have added styles and scripts
	private static $enqueued = FALSE;
	// Whether we have already printed the wp head
	private static $wp_head = FALSE;
	// Used to keep Crayon IDs
	private static $next_id = 0;
	// String to store the regex for capturing mini tags
	private static $alias_regex = '';
	private static $tags_regex = '';
	private static $tag_regexes = array();
	// Defined constants used in bitwise flags
	private static $tag_types = array(
			CrayonSettings::CAPTURE_MINI_TAG,
			CrayonSettings::CAPTURE_PRE,
			CrayonSettings::INLINE_TAG,
			CrayonSettings::PLAIN_TAG,
			CrayonSettings::BACKQUOTE);
	private static $tag_bits = array();

	// Used to detect the shortcode
	const REGEX_CLOSED = '(?:\[\s*crayon(?:-(\w+))?\b([^\]]*)/\s*\])'; // [crayon atts="" /]
	const REGEX_TAG =    '(?:\[\s*crayon(?:-(\w+))?\b([^\]]*)\][\r\n]*?(.*?)[\r\n]*?\[\s*/\s*crayon\s*\])'; // [crayon atts=""] ... [/crayon]
	const REGEX_INLINE_CLASS = '\bcrayon-inline\b';

	const REGEX_CLOSED_NO_CAPTURE = '(?:\[\s*crayon\b[^\]]*/\])';
	const REGEX_TAG_NO_CAPTURE =    '(?:\[\s*crayon\b[^\]]*\][\r\n]*?.*?[\r\n]*?\[/crayon\])';

	const REGEX_QUICK_CAPTURE = '(?:\[\s*crayon[^\]]*\].*?\[\s*/\s*crayon\s*\])|(?:\[\s*crayon[^\]]*/\s*\])';

	const REGEX_BETWEEN_PARAGRAPH = '<p[^<]*>(?:[^<]*<(?!/?p(\s+[^>]*)?>)[^>]+(\s+[^>]*)?>)*[^<]*((?:\[\s*crayon[^\]]*\].*?\[\s*/\s*crayon\s*\])|(?:\[\s*crayon[^\]]*/\s*\]))(?:[^<]*<(?!/?p(\s+[^>]*)?>)[^>]+(\s+[^>]*)?>)*[^<]*</p[^<]*>';
	const REGEX_BETWEEN_PARAGRAPH_SIMPLE = '(<p(?:\s+[^>]*)?>)(.*?)(</p(?:\s+[^>]*)?>)';

	// For [crayon-id/]
	const REGEX_BR_BEFORE = '#<\s*br\s*/?\s*>\s*(\[\s*crayon-\w+\])#msi';
	const REGEX_BR_AFTER = '#(\[\s*crayon-\w+\])\s*<\s*br\s*/?\s*>#msi';

	const REGEX_ID = '#(?<!\$)\[\s*crayon#mi';
	//const REGEX_WITH_ID = '#(\[\s*crayon-\w+)\b([^\]]*["\'])(\s*/?\s*\])#mi';
	const REGEX_WITH_ID = '#\[\s*(crayon-\w+)\b[^\]]*\]#mi';

	const MODE_NORMAL = 0, MODE_JUST_CODE = 1, MODE_PLAIN_CODE = 2;


	//const TAGS = array(CrayonSettings::CAPTURE_MINI_TAG => 1 | 1);

	// Public Methods =========================================================

	public static function post_captures() {
		return self::$post_queue;
	}

	// Methods ================================================================

	private function __construct() {
	}

	public static function regex() {
		return '#(?<!\$)(?:'. self::REGEX_CLOSED .'|'. self::REGEX_TAG .')(?!\$)#msi';
	}

	public static function regex_with_id($id) {
		return '#\[\s*(crayon-'.$id.')\b[^\]]*\]#mi';
	}

	public static function regex_no_capture() {
		return '#(?<!\$)(?:'. self::REGEX_CLOSED_NO_CAPTURE .'|'. self::REGEX_TAG_NO_CAPTURE .')(?!\$)#msi';
	}

	/**
	 * Adds the actual Crayon instance.
	 * $mode can be: 0 = return crayon content, 1 = return only code, 2 = return only plain code
	 */
	public static function shortcode($atts, $content = NULL, $id = NULL) {
		CrayonLog::debug('shortcode');

		// Load attributes from shortcode
		$allowed_atts = array('url' => NULL, 'lang' => NULL, 'title' => NULL, 'mark' => NULL, 'range' => NULL, 'inline' => NULL);
		$filtered_atts = shortcode_atts($allowed_atts, $atts);

		// Clean attributes
		$keys = array_keys($filtered_atts);
		for ($i = 0; $i < count($keys); $i++) {
			$key = $keys[$i];
			$value = $filtered_atts[$key];
			if ($value !== NULL) {
				$filtered_atts[$key] = trim(strip_tags($value));
			}
		}

		// Contains all other attributes not found in allowed, used to override global settings
		$extra_attr = array();
		if (!empty($atts)) {
			$extra_attr = array_diff_key($atts, $allowed_atts);
			$extra_attr = CrayonSettings::smart_settings($extra_attr);
		}
		$url = $lang = $title = $mark = $range = $inline = '';
		extract($filtered_atts);

		$crayon = self::instance($extra_attr, $id);

		// Set URL
		$crayon->url($url);
		$crayon->code($content);
		// Set attributes, should be set after URL to allow language auto detection
		$crayon->language($lang);
		$crayon->title($title);
		$crayon->marked($mark);
		$crayon->range($range);

		$crayon->is_inline($inline);

		// Determine if we should highlight
		$highlight = array_key_exists('highlight', $atts) ? CrayonUtil::str_to_bool($atts['highlight'], FALSE) : TRUE;
		$crayon->is_highlighted($highlight);
		return $crayon;
	}

	/* Returns Crayon instance */
	public static function instance($extra_attr = array(), $id = NULL) {
		CrayonLog::debug('instance');

		// Create Crayon
		$crayon = new CrayonHighlighter();

		/* Load settings and merge shortcode attributes which will override any existing.
		 * Stores the other shortcode attributes as settings in the crayon. */
		if (!empty($extra_attr)) {
			$crayon->settings($extra_attr);
		}
		if (!empty($id)) {
			$crayon->id($id);
		}

		return $crayon;
	}

	/* For manually highlighting code, useful for other PHP contexts */
	public static function highlight($code) {
		$crayon_str = '';

		$captures = CrayonWP::capture_crayons(0, $code);
		$the_captures = $captures['capture'];
		$the_content = $captures['content'];
		foreach ($the_captures as $capture) {
			$id = $capture['id'];
			$atts = $capture['atts'];
			$no_enqueue = array(
					CrayonSettings::ENQUEUE_THEMES => FALSE,
					CrayonSettings::ENQUEUE_FONTS => FALSE);
			$atts = array_merge($atts, $no_enqueue);
			$code = $capture['code'];
			$crayon = CrayonWP::shortcode($atts, $code, $id);
			$crayon_formatted = $crayon->output(TRUE, FALSE);
			$the_content = CrayonUtil::preg_replace_escape_back(self::regex_with_id($id), $crayon_formatted, $the_content, 1, $count);
		}

		return $the_content;
	}

	/* Uses the main query */
	public static function wp() {
		CrayonLog::debug('wp (global)');

		global $wp_the_query;
		$posts = $wp_the_query->posts;
		self::the_posts($posts);
	}

	// TODO put args into an array
	public static function capture_crayons($wp_id, $wp_content, $extra_settings = array(), $args = array()) {
		extract($args);
		CrayonUtil::set_var($callback, NULL);
		CrayonUtil::set_var($ignore, TRUE);
		CrayonUtil::set_var($preserve_atts, FALSE);
		CrayonUtil::set_var($flags, NULL);

		// Will contain captured crayons and altered $wp_content
		$capture = array('capture' => array(), 'content' => $wp_content, 'has_captured' => FALSE);

		// Flags for which Crayons to convert
		$in_flag = self::in_flag($flags);

		CrayonLog::debug('capture for id ' . $wp_id . ' len ' . strlen($wp_content));

		// Convert <pre> tags to crayon tags, if needed
		if (CrayonGlobalSettings::val(CrayonSettings::CAPTURE_PRE) && $in_flag[CrayonSettings::CAPTURE_PRE]) {
			// XXX This will fail if <pre></pre> is used inside another <pre></pre>
			$wp_content = preg_replace_callback('#(?<!\$)<\s*pre(?=(?:([^>]*)\bclass\s*=\s*(["\'])(.*?)\2([^>]*))?)([^>]*)>(.*?)<\s*/\s*pre\s*>#msi', 'CrayonWP::pre_tag', $wp_content);
		}

		// Convert mini [php][/php] tags to crayon tags, if needed
		if (CrayonGlobalSettings::val(CrayonSettings::CAPTURE_MINI_TAG) && $in_flag[CrayonSettings::CAPTURE_MINI_TAG]) {
			$wp_content = preg_replace('#(?<!\$)\[\s*('.self::$alias_regex.')\b([^\]]*)\](.*?)\[\s*/\s*(?:\1)\s*\](?!\$)#msi', '[crayon lang="\1" \2]\3[/crayon]', $wp_content);
			$wp_content = preg_replace('#(?<!\$)\[\s*('.self::$alias_regex.')\b([^\]]*)/\s*\](?!\$)#msi', '[crayon lang="\1" \2 /]', $wp_content);
		}

		// Convert inline {php}{/php} tags to crayon tags, if needed
		if (CrayonGlobalSettings::val(CrayonSettings::INLINE_TAG) && $in_flag[CrayonSettings::INLINE_TAG]) {
			$wp_content = preg_replace('#(?<!\$)\{\s*('.self::$alias_regex.')\b([^\}]*)\}(.*?)\{/(?:\1)\}(?!\$)#msi', '[crayon lang="\1" inline="true" \2]\3[/crayon]', $wp_content);
			// Convert <span class="crayon-inline"> tags to inline crayon tags
			$wp_content = preg_replace_callback('#(?<!\$)<\s*span([^>]*)\bclass\s*=\s*(["\'])(.*?)\2([^>]*)>(.*?)<\s*/\s*span\s*>#msi', 'CrayonWP::span_tag', $wp_content);
		}

		// Convert [plain] tags into <pre><code></code></pre>, if needed
		if (CrayonGlobalSettings::val(CrayonSettings::PLAIN_TAG) && $in_flag[CrayonSettings::PLAIN_TAG]) {
			$wp_content = preg_replace_callback('#(?<!\$)\[\s*plain\s*\](.*?)\[\s*/\s*plain\s*\]#msi', 'CrayonFormatter::plain_code', $wp_content);
		}

		// Add IDs to the Crayons
		CrayonLog::debug('capture adding id ' . $wp_id . ' , now has len ' . strlen($wp_content));
		$wp_content = preg_replace_callback(self::REGEX_ID, 'CrayonWP::add_crayon_id', $wp_content);

		CrayonLog::debug('capture added id ' . $wp_id . ' : ' . strlen($wp_content));

		// Only include if a post exists with Crayon tag
		preg_match_all(self::regex(), $wp_content, $matches);

		CrayonLog::debug('capture ignore for id ' . $wp_id . ' : ' . strlen($capture['content']) . ' vs ' . strlen($wp_content));

		if ( count($matches[0]) != 0 ) {

			// Crayons found! Load settings first to ensure global settings loaded
			CrayonSettingsWP::load_settings();
			$capture['has_captured'] = TRUE;

			CrayonLog::debug('CAPTURED FOR ID ' . $wp_id);

			$full_matches = $matches[0];
			$closed_ids = $matches[1];
			$closed_atts = $matches[2];
			$open_ids = $matches[3];
			$open_atts = $matches[4];
			$contents = $matches[5];

			// Make sure we enqueue the styles/scripts
			$enqueue = TRUE;

			for ($i = 0; $i < count($full_matches); $i++) {
				// Get attributes
				if ( !empty($closed_atts[$i]) ) {
					$atts = $closed_atts[$i];
				} else if ( !empty($open_atts[$i]) ) {
					$atts = $open_atts[$i];
				} else {
					$atts = '';
				}

				// Capture attributes
				preg_match_all('#([^="\'\s]+)[\t ]*=[\t ]*("|\')(.*?)\2#', $atts, $att_matches);
				// Add extra attributes
				$atts_array = $extra_settings;
				if ( count($att_matches[0]) != 0 ) {
					for ($j = 0; $j < count($att_matches[1]); $j++) {
						$atts_array[trim(strtolower($att_matches[1][$j]))] = trim($att_matches[3][$j]);
					}
				}

				// Capture theme
				$theme_id = array_key_exists(CrayonSettings::THEME, $atts_array) ? $atts_array[CrayonSettings::THEME] : '';
				$theme = CrayonResources::themes()->get($theme_id);
				// If theme not found, use fallbacks
				if (!$theme) {
					// Given theme is invalid, try global setting
					$theme_id = CrayonGlobalSettings::val(CrayonSettings::THEME);
					$theme = CrayonResources::themes()->get($theme_id);
					if (!$theme) {
						// Global setting is invalid, fall back to default
						$theme = CrayonResources::themes()->get_default();
						$theme_id = CrayonThemes::DEFAULT_THEME;
					}
				}
				// If theme is now valid, change the array
				if ($theme) {
					if (!$preserve_atts || isset($atts_array[CrayonSettings::THEME])) {
						$atts_array[CrayonSettings::THEME] = $theme_id;
					}
					$theme->used(TRUE);
				}

				// Capture font
				$font_id = array_key_exists(CrayonSettings::FONT, $atts_array) ? $atts_array[CrayonSettings::FONT] : '';
				$font = CrayonResources::fonts()->get($font_id);
				// If font not found, use fallbacks
				if (!$font) {
					// Given font is invalid, try global setting
					$font_id = CrayonGlobalSettings::val(CrayonSettings::FONT);
					$font = CrayonResources::fonts()->get($font_id);
					if (!$font) {
						// Global setting is invalid, fall back to default
						$font = CrayonResources::fonts()->get_default();
						$font_id = CrayonFonts::DEFAULT_FONT;
					}
				}

				// If font is now valid, change the array
				if ($font/* != NULL && $font_id != CrayonFonts::DEFAULT_FONT*/) {
					if (!$preserve_atts || isset($atts_array[CrayonSettings::FONT])) {
						$atts_array[CrayonSettings::FONT] = $font_id;
					}
					$font->used(TRUE);
				}

				// Add array of atts and content to post queue with key as post ID
				// XXX If at this point no ID is added we have failed!
				$id = !empty($open_ids[$i]) ? $open_ids[$i] : $closed_ids[$i];
				//if ($ignore) {
				$code = self::crayon_remove_ignore($contents[$i]);
				//}
				$c = array('post_id'=>$wp_id, 'atts'=>$atts_array, 'code'=>$code);
				$capture['capture'][$id] = $c;
				CrayonLog::debug('capture finished for post id ' . $wp_id . ' crayon-id ' . $id . ' atts: ' . count($atts_array) . ' code: ' . strlen($code));
				$is_inline = isset($atts_array['inline']) && CrayonUtil::str_to_bool($atts_array['inline'], FALSE) ? '-i' : '';
				if ($callback === NULL) {
					$wp_content = str_replace($full_matches[$i], '[crayon-'.$id.$is_inline.'/]', $wp_content);
				} else {
					$wp_content = call_user_func($callback, $c, $full_matches[$i], $id, $is_inline, $wp_content);
				}
			}

		}

		if ($ignore) {
			// We need to escape ignored Crayons, since they won't be captured
			// XXX Do this after replacing the Crayon with the shorter ID tag, otherwise $full_matches will be different from $wp_content
			$wp_content = self::crayon_remove_ignore($wp_content);
		}

		// Convert `` backquote tags into <code></code>, if needed
		// XXX Some code may contain `` so must do it after all Crayons are captured
		if (CrayonGlobalSettings::val(CrayonSettings::BACKQUOTE)) {
			$wp_content = preg_replace('#(?<!\\\\)`([^`]*)`#msi', '<code>$1</code>', $wp_content);
		}

		$capture['content'] = $wp_content;
		return $capture;
	}

	/* Search for Crayons in posts and queue them for creation */
	public static function the_posts($posts) {
		CrayonLog::debug('the_posts');

		// Whether to enqueue syles/scripts
		$enqueue = FALSE;
		CrayonSettingsWP::load_settings(TRUE); // Load just the settings from db, for now

		self::init_tags_regex();
		$crayon_posts = CrayonSettingsWP::load_posts(); // Loads posts containing crayons

		// Search for shortcode in posts
		foreach ($posts as $post) {
			$wp_id = $post->ID;
			if (!in_array($wp_id, $crayon_posts)) {
				// If we get query for a page, then that page might have a template and load more posts containing Crayons
				// By this state, we would be unable to enqueue anything (header already written).
				if (CrayonGlobalSettings::val(CrayonSettings::SAFE_ENQUEUE) && is_page($wp_id)) {
					CrayonGlobalSettings::set(CrayonSettings::ENQUEUE_THEMES, false);
					CrayonGlobalSettings::set(CrayonSettings::ENQUEUE_FONTS, false);
				}
				// Only include crayon posts
				continue;
			}

			$id_str = strval($wp_id);

			if ( isset(self::$post_captures[$id_str]) ) {
				// Don't capture twice
				// XXX post->post_content is reset each loop, replace content
				// Doing this might cause content changed by other plugins between the last loop
				// to fail, so be cautious
				$post->post_content = self::$post_captures[$id_str];
				continue;
			}
			// Capture post Crayons
			$captures = self::capture_crayons($post->ID, $post->post_content);

			// XXX Careful not to undo changes by other plugins
			// XXX Must replace to remove $ for ignored Crayons
			$post->post_content = $captures['content'];
			self::$post_captures[$id_str] = $captures['content'];
			if ($captures['has_captured'] === TRUE) {
				$enqueue = TRUE;
				self::$post_queue[$id_str] = array();
				foreach ($captures['capture'] as $capture_id=>$capture_content) {
					self::$post_queue[$id_str][$capture_id] = $capture_content;
				}
			}

			// Search for shortcode in comments
			if (CrayonGlobalSettings::val(CrayonSettings::COMMENTS)) {
				$comments = get_comments(array('post_id' => $post->ID));
				foreach ($comments as $comment) {
					$id_str = strval($comment->comment_ID);
					if ( isset(self::$comment_queue[$id_str]) ) {
						// Don't capture twice
						continue;
					}
					// Capture comment Crayons, decode their contents if decode not specified
					$captures = self::capture_crayons($comment->comment_ID, $comment->comment_content, array(CrayonSettings::DECODE => TRUE));
					self::$comment_captures[$id_str] = $captures['content'];
					if ($captures['has_captured'] === TRUE) {
						$enqueue = TRUE;
						self::$comment_queue[$id_str] = array();
						foreach ($captures['capture'] as $capture_id=>$capture_content) {
							self::$comment_queue[$id_str][$capture_id] = $capture_content;
						}
					}
				}
			}
		}

		if (!is_admin() && $enqueue && !self::$enqueued) {
			// Crayons have been found and we enqueue efficiently
			self::enqueue_resources();
		}

		return $posts;
	}

	private static function add_crayon_id($content) {
		$uid = $content[0].'-'.uniqid();
		CrayonLog::debug('add_crayon_id ' . $uid);
		return $uid;
	}

	private static function get_crayon_id() {
		return self::$next_id++;
	}

	private static function enqueue_resources() {
		CrayonLog::debug('enqueue');

		global $CRAYON_VERSION;
		wp_enqueue_style('crayon_style', plugins_url(CRAYON_STYLE, __FILE__), array(), $CRAYON_VERSION);
		wp_enqueue_style('crayon_global_style', plugins_url(CRAYON_STYLE_GLOBAL, __FILE__), array(), $CRAYON_VERSION);
		wp_enqueue_script('crayon_util_js', plugins_url(CRAYON_JS_UTIL, __FILE__), array('jquery'), $CRAYON_VERSION);
		wp_enqueue_script('crayon_js', plugins_url(CRAYON_JS, __FILE__), array('jquery', 'crayon_util_js'), $CRAYON_VERSION);
		wp_enqueue_script('crayon_jquery_popup', plugins_url(CRAYON_JQUERY_POPUP, __FILE__), array('jquery'), $CRAYON_VERSION);
		self::$enqueued = TRUE;
	}

	private static function init_tags_regex($force = FALSE, $flags = NULL) {
		self::init_tag_bits();

		if ($force || self::$tags_regex == "") {
			// Check which tags are in $flags. If it's NULL, then all flags are true.
			$in_flag = self::in_flag($flags);
				
			if ( ($in_flag[CrayonSettings::CAPTURE_MINI_TAG] && CrayonGlobalSettings::val(CrayonSettings::CAPTURE_MINI_TAG)) ||
					($in_flag[CrayonSettings::INLINE_TAG] && CrayonGlobalSettings::val(CrayonSettings::INLINE_TAG)) ) {
				$aliases = CrayonResources::langs()->ids_and_aliases();
				for ($i = 0; $i < count($aliases); $i++) {
					$alias = $aliases[$i];
					$alias_regex = CrayonUtil::esc_hash(CrayonUtil::esc_regex($alias));
					if ($i != count($aliases) - 1) {
						$alias_regex .= '|';
					}
					self::$alias_regex .= $alias_regex;
				}
			}
				
			// Add other tags
			self::$tags_regex = '#(?<!\$)(?:(\s*\[\s*crayon\b)';
				
			$tag_regexes = array(
					CrayonSettings::CAPTURE_MINI_TAG => '([\[]\s*('.self::$alias_regex.')\b)',
					CrayonSettings::CAPTURE_PRE => '(<\s*pre\b)',
					CrayonSettings::INLINE_TAG => '('.self::REGEX_INLINE_CLASS.')'.'|([\{]\s*('.self::$alias_regex.'))',
					CrayonSettings::PLAIN_TAG => '(\s*\[\s*plain\b)',
					CrayonSettings::BACKQUOTE => '(`[^`]*`)'
			);
				
			foreach ($tag_regexes as $tag=>$regex) {
				if ($in_flag[$tag] && CrayonGlobalSettings::val($tag)) {
					self::$tags_regex .= '|' . $regex;
				}
			}
			self::$tags_regex .= ')#msi';
				
				
			// 			if (CrayonGlobalSettings::val(CrayonSettings::CAPTURE_MINI_TAG)) {
			// 				self::$tags_regex .= '|([\[]\s*('.self::$alias_regex.'))';
			// 			}
			// 			if (CrayonGlobalSettings::val(CrayonSettings::CAPTURE_PRE)) {
			// 				self::$tags_regex .= '|(<\s*pre\b)';
			// 			}
			// 			if (CrayonGlobalSettings::val(CrayonSettings::INLINE_TAG)) {
			// 				self::$tags_regex .= '|('.self::REGEX_INLINE_CLASS.')'.'|([\{]\s*('.self::$alias_regex.'))';
			// 			}
			// 			if (CrayonGlobalSettings::val(CrayonSettings::PLAIN_TAG)) {
			// 				self::$tags_regex .= '|(\s*\[\s*plain\b)';
			// 			}
			// 			if (CrayonGlobalSettings::val(CrayonSettings::BACKQUOTE)) {
			// 				self::$tags_regex .= '|(`[^`]*`)';
			// 			}
			// 			self::$tags_regex .= '#msi';
		}
	}

	private static function init_tag_bits() {
		if (count(self::$tag_bits) == 0) {
			$values = array();
			for ($i = 0; $i < count(self::$tag_types); $i++) {
				$j = pow(2, $i);
				self::$tag_bits[self::$tag_types[$i]] = $j;
			}
		}
	}

	public static function tag_bit($tag) {
		self::init_tag_bits();
		if (isset(self::$tag_bits[$tag])) {
			return self::$tag_bits[$tag];
		} else {
			return null;
		}
	}

	public static function in_flag($flags) {
		$in_flag = array();
		foreach (self::$tag_types as $tag) {
			$in_flag[$tag] = $flags === NULL || ($flags & self::tag_bit($tag)) > 0;
		}
		return $in_flag;
	}

	// Add Crayon into the_content
	public static function the_content($the_content) {
		CrayonLog::debug('the_content');

		// Some themes make redundant queries and don't need extra work...
		if (strlen($the_content) == 0) {
			CrayonLog::debug('the_content blank');
			return $the_content;
		}

		global $post;

		// Go through queued posts and find crayons
		$post_id = strval($post->ID);

		if (self::$is_excerpt) {
			CrayonLog::debug('excerpt');
			if (CrayonGlobalSettings::val(CrayonSettings::EXCERPT_STRIP)) {
				CrayonLog::debug('excerpt strip');
				// Remove Crayon from content if we are displaying an excerpt
				$the_content = preg_replace(self::REGEX_WITH_ID, '', $the_content);
			}
			// Otherwise Crayon remains with ID and replaced later
			return $the_content;
		}

		// Find if this post has Crayons
		if ( array_key_exists($post_id, self::$post_queue) ) {
			// XXX We want the plain post content, no formatting
			$the_content_original = $the_content;

			// Replacing may cause <p> tags to become disjoint with a <div> inside them, close and reopen them if needed
			$the_content = preg_replace_callback('#' . self::REGEX_BETWEEN_PARAGRAPH_SIMPLE . '#msi', 'CrayonWP::add_paragraphs', $the_content);
			// Loop through Crayons
			$post_in_queue = self::$post_queue[$post_id];
			foreach ($post_in_queue as $id=>$v) {
				$atts = $v['atts'];
				$content = $v['code']; // The code we replace post content with
				$crayon = self::shortcode($atts, $content, $id);
				if (is_feed()) {
					// Convert the plain code to entities and put in a <pre></pre> tag
					$crayon_formatted = CrayonFormatter::plain_code($crayon->code(), $crayon->setting_val(CrayonSettings::DECODE));
				} else {
					// Apply shortcode to the content
					$crayon_formatted = $crayon->output(TRUE, FALSE);
				}
				// Replace the code with the Crayon
				CrayonLog::debug('the_content: id '.$post_id. ' has UID ' . $id . ' : ' . intval(stripos($the_content, $id) !== FALSE) );
				$the_content = CrayonUtil::preg_replace_escape_back(self::regex_with_id($id), $crayon_formatted, $the_content, 1, $count);
				CrayonLog::debug('the_content: REPLACED for id '.$post_id. ' from len ' . strlen($the_content_original) . ' to ' . strlen($the_content));
			}
		}

		return $the_content;
	}

	public static function pre_comment_text($text) {
		global $comment;
		$comment_id = strval($comment->comment_ID);
		if ( array_key_exists($comment_id, self::$comment_captures) ) {
			// Replace with IDs now that we need to
			$text = self::$comment_captures[$comment_id];
		}
		return $text;
	}

	public static function comment_text($text) {
		global $comment;
		$comment_id = strval($comment->comment_ID);
		// Find if this post has Crayons
		if ( array_key_exists($comment_id, self::$comment_queue) ) {
			// XXX We want the plain post content, no formatting
			$the_content_original = $text;
			// Loop through Crayons
			$post_in_queue = self::$comment_queue[$comment_id];

			foreach ($post_in_queue as $id=>$v) {
				$atts = $v['atts'];
				$content = $v['code']; // The code we replace post content with
				$crayon = self::shortcode($atts, $content, $id);
				$crayon_formatted = $crayon->output(TRUE, FALSE);
				// Replacing may cause <p> tags to become disjoint with a <div> inside them, close and reopen them if needed
				if (!$crayon->is_inline()) {
					$text = preg_replace_callback('#' . self::REGEX_BETWEEN_PARAGRAPH_SIMPLE . '#msi', 'CrayonWP::add_paragraphs', $text);
				}
				// Replace the code with the Crayon
				$text = CrayonUtil::preg_replace_escape_back(self::regex_with_id($id), $crayon_formatted, $text, 1, $text);
			}
		}
		return $text;
	}

	public static function add_paragraphs($capture) {
		if (count($capture) != 4) {
			CrayonLog::debug('add_paragraphs: 0');
			return $capture[0];
		}
		$capture[2] = preg_replace('#(?:<\s*br\s*/\s*>\s*)?(\[\s*crayon-\w+/\])(?:<\s*br\s*/\s*>\s*)?#msi', '</p>$1<p>', $capture[2]);
		// If [crayon appears right after <p> then we will generate <p></p>, remove all these
		$paras = $capture[1].$capture[2].$capture[3];
		return $paras;
	}

	// Remove Crayons from the_excerpt
	public static function the_excerpt($the_excerpt) {
		CrayonLog::debug('excerpt');
		global $post;
		if (!empty($post->post_excerpt)) {
			// Use custom excerpt if defined
			$the_excerpt = wpautop($post->post_excerpt);
		} else {
			// Pass wp_trim_excerpt('') to gen from content (and remove [crayons])
			$the_excerpt = wpautop(wp_trim_excerpt(''));
		}
		// XXX Returning "" may cause it to default to full contents...
		return $the_excerpt . ' ';
	}

	// Refactored, used to capture pre and span tags which have settings in class attribute
	public static function class_tag($matches) {
		// If class exists, atts is not captured
		$pre_class = $matches[1];
		$quotes = $matches[2];
		$class = $matches[3];
		$post_class = $matches[4];
		$atts = $matches[5];
		$content = $matches[6];

		// If we find a crayon=false in the attributes, or a crayon[:_]false in the class, then we should not capture
		$ignore_regex_atts = '#crayon\s*=\s*(["\'])\s*(false|no|0)\s*\1#msi';
		$ignore_regex_class = '#crayon\s*[:_]\s*(false|no|0)#msi';
		if (preg_match($ignore_regex_atts, $atts) !== 0 ||
				preg_match($ignore_regex_class, $class) !== 0 ) {
			return $matches[0];
		}

		if (!empty($class)) {
			// crayon-inline is turned into inline="1"
			$class = preg_replace('#'.self::REGEX_INLINE_CLASS.'#mi', 'inline="1"', $class);
			// "setting[:_]value" style settings in the class attribute
			$class = preg_replace('#\b([A-Za-z-]+)[_:](\S+)#msi', '$1='.$quotes.'$2'.$quotes, $class);
		}

		// data-url is turned into url=""
		if (!empty($post_class)) {
			$post_class = preg_replace('#\bdata-url\s*=#mi', 'url=', $post_class);
		}
		if (!empty($pre_class)) {
			$pre_class = preg_replace('#\bdata-url\s*=#mi', 'url=', $post_class);
		}

		if (!empty($class)) {
			return "[crayon $pre_class $class $post_class]{$content}[/crayon]";
		} else {
			return "[crayon $atts]{$content}[/crayon]";
		}
	}

	// Capture span tag and extract settings from the class attribute, if present.
	public static function span_tag($matches) {
		// Only use <span> tags with crayon-inline class
		if (preg_match('#'.self::REGEX_INLINE_CLASS.'#mi', $matches[3])) {
			// no $atts
			$matches[6] = $matches[5];
			$matches[5] = '';
			return self::class_tag($matches);
		} else {
			// Don't turn regular <span>s into Crayons
			return $matches[0];
		}
	}

	// Capture pre tag and extract settings from the class attribute, if present.
	public static function pre_tag($matches) {
		return self::class_tag($matches);
	}

	// Check if the $ notation has been used to ignore [crayon] tags within posts and remove all matches
	// Can also remove if used without $ as a regular crayon
	public static function crayon_remove_ignore($the_content, $ignore_flag = '$') {
		if ($ignore_flag == FALSE) {
			$ignore_flag = '';
		}
		$ignore_flag_regex = preg_quote($ignore_flag);

		$the_content = preg_replace('#'.$ignore_flag_regex.'(\s*\[\s*crayon)#msi', '$1', $the_content);
		$the_content = preg_replace('#(crayon\s*\])\s*\$#msi', '$1', $the_content);

		if (CrayonGlobalSettings::val(CrayonSettings::CAPTURE_PRE)) {
			$the_content = str_ireplace(array($ignore_flag.'<pre', 'pre>'.$ignore_flag), array('<pre', 'pre>'), $the_content);
			// Remove any <code> tags wrapping around the whole code, since we won't needed them
			$the_content = preg_replace('#(^\s*<\s*code[^>]*>)|(<\s*/\s*code[^>]*>\s*$)#msi', '', $the_content);
		}
		if (CrayonGlobalSettings::val(CrayonSettings::PLAIN_TAG)) {
			$the_content = str_ireplace(array($ignore_flag.'[plain', 'plain]'.$ignore_flag), array('[plain', 'plain]'), $the_content);
		}
		if (CrayonGlobalSettings::val(CrayonSettings::CAPTURE_MINI_TAG) ||
				CrayonGlobalSettings::val(CrayonSettings::INLINE_TAG)) {
			self::init_tags_regex();
			// 			$the_content = preg_replace('#'.$ignore_flag_regex.'\s*([\[\{])\s*('. self::$alias_regex .')#', '$1$2', $the_content);
			// 			$the_content = preg_replace('#('. self::$alias_regex .')\s*([\]\}])\s*'.$ignore_flag_regex.'#', '$1$2', $the_content);
			$the_content = preg_replace('#'.$ignore_flag_regex.'(\s*[\[\{]\s*('. self::$alias_regex .')[^\]]*[\]\}])#', '$1', $the_content);
		}
		if (CrayonGlobalSettings::val(CrayonSettings::BACKQUOTE)) {
			$the_content = str_ireplace('\\`', '`', $the_content);
		}
		return $the_content;
	}

	public static function wp_head() {
		CrayonLog::debug('head');

		self::$wp_head = TRUE;
		if (!self::$enqueued) {
			CrayonLog::debug('head: missed enqueue');
			// We have missed our chance to check before enqueuing. Use setting to either load always or only in the_post
			CrayonSettingsWP::load_settings(TRUE); // Ensure settings are loaded
			if (!CrayonGlobalSettings::val(CrayonSettings::EFFICIENT_ENQUEUE)) {
				CrayonLog::debug('head: force enqueue');
				// Efficient enqueuing disabled, always load despite enqueuing or not in the_post
				self::enqueue_resources();
			}
		}
		// Enqueue Theme CSS
		if (CrayonGlobalSettings::val(CrayonSettings::ENQUEUE_THEMES)) {
			self::crayon_theme_css();
		}
		// Enqueue Font CSS
		if (CrayonGlobalSettings::val(CrayonSettings::ENQUEUE_FONTS)) {
			self::crayon_font_css();
		}
	}

	public static function save_post($update_id, $post) {
		// 		if (wp_is_post_revision($id)) {
		// 			// Ignore revisions
		// 			return;
		// 		}

		$id = $post->ID;

		self::init_tags_regex();

		if (preg_match(self::$tags_regex, $post->post_content)) {
			CrayonSettingsWP::add_post($id);
		} else {
			$found = FALSE;
			CrayonSettingsWP::load_settings(TRUE);
			if (CrayonGlobalSettings::val(CrayonSettings::COMMENTS)) {
				$comments = get_comments(array('post_id' => $id));
				foreach ($comments as $comment) {
					$found = self::save_comment($comment->comment_ID, NULL, $comment);
					if ($found) {
						break;
					}
				}
			}
			if (!$found) {
				CrayonSettingsWP::remove_post($id);
			}
		}
	}

	public static function save_comment($id, $is_spam = NULL, $comment = NULL) {
		self::init_tags_regex();

		if ($comment == NULL) {
			$comment = get_comment($id);
		}

		$content = $comment->comment_content;
		$post_id = $comment->comment_post_ID;

		if (preg_match(self::$tags_regex, $content)) {
			CrayonSettingsWP::add_post($post_id);
			return TRUE;
		} else {
			return FALSE;
		}
	}

	public static function crayon_theme_css() {
		global $CRAYON_VERSION;
		$css = CrayonResources::themes()->get_used_css();
		foreach ($css as $theme=>$url) {
			wp_enqueue_style('crayon-theme-'.$theme, $url, array(), $CRAYON_VERSION);
		}
	}

	public static function crayon_font_css() {
		global $CRAYON_VERSION;
		$css = CrayonResources::fonts()->get_used_css();
		foreach ($css as $font_id=>$url) {
			wp_enqueue_style('crayon-font-'.$font_id, $url, array(), $CRAYON_VERSION);
		}
	}

	public static function init($request) {
		CrayonLog::debug('init');
		crayon_load_plugin_textdomain();
	}

	/**
	 * Return an array of post IDs where crayons occur.
	 * Comments are ignored by default.
	 */
	public static function scan_posts($init_regex = TRUE, $check_comments = FALSE) {
		if ($init_regex) {
			// We can skip this if needed
			self::init_tags_regex();
		}

		$crayon_posts = array();
		$query = new WP_Query(array('post_type' => 'any', 'suppress_filters' => TRUE, 'posts_per_page' => '-1'));
		foreach ($query->posts as $post) {
			$post_id = $post->ID;
			if (preg_match(self::$tags_regex, $post->post_content)) {
				$crayon_posts[] = $post_id;
			} else if ($check_comments) {
				CrayonSettingsWP::load_settings(TRUE);
				if (CrayonGlobalSettings::val(CrayonSettings::COMMENTS)) {
					$comments = get_comments(array('post_id' => $post_id));
					foreach ($comments as $comment) {
						if (preg_match(self::$tags_regex, $comment->comment_content)) {
							$crayon_posts[] = $post_id;
							break;
						}
					}
				}
			}
		}
		return $crayon_posts;
	}

	public static function install() {
		self::update();
	}

	public static function uninstall() {

	}

	public  static function update() {
		// Upgrade database and settings
		global $CRAYON_VERSION;
		$settings = CrayonSettingsWP::get_settings();
		if ($settings === NULL || !isset($settings[CrayonSettings::VERSION])) {
			return;
		}

		$version = $settings[CrayonSettings::VERSION];
		$defaults = CrayonSettings::get_defaults_array();
		$touched = FALSE;

		if ($version < '1.7.21') {
			$settings[CrayonSettings::SCROLL] = $defaults[CrayonSettings::SCROLL];
			$touched = TRUE;
		}

		if ($version < '1.7.23' && $settings[CrayonSettings::FONT] == 'theme-font') {
			$settings[CrayonSettings::FONT] = $defaults[CrayonSettings::FONT];
			$touched = TRUE;
		}

		if ($touched) {
			$settings[CrayonSettings::VERSION] = $CRAYON_VERSION;
			CrayonSettingsWP::save_settings($settings);
		}
	}

	public static function basename() {
		return plugin_basename(__FILE__);
	}

	// This should never be called through AJAX, only server side, since WP will not be loaded
	public static function wp_load_path() {
		if (defined('ABSPATH')) {
			return ABSPATH . 'wp-load.php';
		} else {
			CrayonLog::syslog('wp_load_path could not find value for ABSPATH');
		}
	}

	public static function pre_excerpt($e) {
		CrayonLog::debug('pre_excerpt');
		self::$is_excerpt = TRUE;
		return $e;
	}

	public static function post_excerpt($e) {
		CrayonLog::debug('post_excerpt');
		self::$is_excerpt = FALSE;
		$e = self::the_content($e);
		return $e;
	}

	public static function post_get_excerpt($e) {
		CrayonLog::debug('post_get_excerpt');
		self::$is_excerpt = FALSE;
		return $e;
	}

	/**
	 * Converts Crayon tags found in WP to <pre> form.
	 * XXX: This will alter blog content, so backup before calling.
	 * XXX: Do NOT call this while updating posts or comments, it may cause an infinite loop or fail
	 */
	public static function convert_tags($just_check = FALSE) {
		$result = self::can_convert_tags(TRUE);
		if ($result === FALSE) {
			return;
		} else {
			$crayon_posts = $result[0];
			$flags = $result[1];
		}

		$args = array(
				'callback' => 'CrayonWP::capture_replace_pre',
				'ignore' => FALSE,
				'preserve_atts' => TRUE,
				'flags' => $flags
		);

		foreach ($crayon_posts as $postID) {
			$post = get_post($postID);
			$post_content = $post->post_content;
			$post_captures = self::capture_crayons($postID, $post_content, array(), $args);
				
			$post_obj = array();
			$post_obj['ID'] = $postID;
			$post_obj['post_content'] = $post_captures['content'];
			wp_update_post($post_obj);
			CrayonLog::syslog("Converted Crayons in post ID $postID to pre tags", 'CONVERT');
				
			if (CrayonGlobalSettings::val(CrayonSettings::COMMENTS)) {
				$comments = get_comments(array('post_id' => $postID));
				foreach ($comments as $comment) {
					$commentID = $comment->comment_ID;
					$comment_captures = self::capture_crayons($commentID, $comment->comment_content, array(CrayonSettings::DECODE => TRUE), $args);
						
					$comment_obj = array();
					$comment_obj['comment_ID'] = $commentID;
					$comment_obj['comment_content'] = $comment_captures['content'];
					wp_update_comment($comment_obj);
					CrayonLog::syslog("Converted Crayons in post ID $postID, comment ID $commentID to pre tags", 'CONVERT');
				}
			}
				
		}
	}

	public static function can_convert_tags($return = FALSE) {
		CrayonSettingsWP::load_settings(TRUE);
		self::init_tag_bits();
		$flags = self::tag_bit(CrayonSettings::CAPTURE_MINI_TAG) |
		self::tag_bit(CrayonSettings::INLINE_TAG) |
		self::tag_bit(CrayonSettings::PLAIN_TAG);
		self::init_tags_regex(TRUE, $flags);

		// These posts (or comments) will contain the old tag markup
		$crayon_posts = self::scan_posts(FALSE, TRUE);

		// Reset to all flags for other queries
		self::init_tags_regex(TRUE);
		if (count($crayon_posts) > 0) {
			if ($return) {
				return array($crayon_posts, $flags);
			} else {
				return TRUE;
			}
		} else {
			return FALSE;
		}
	}

	// Used as capture_crayons callback
	public static function capture_replace_pre($capture, $original, $id, $is_inline, $wp_content) {
		$atts = array();
		$atts['class'] = CrayonUtil::html_attributes($capture['atts'], CrayonGlobalSettings::val_str(CrayonSettings::ATTR_SEP), '');
		// 		$has_decode = isset($capture['atts'][CrayonSettings::DECODE]);
		// 		$default = CrayonGlobalSettings::val(CrayonSettings::DECODE);
		// 		$encoded = $has_decode ? CrayonUtil::str_to_bool($capture['atts'][CrayonSettings::DECODE], $default) : $default;
		// 		CrayonUtil::bool_to_str($encoded);
		return str_replace($original, CrayonUtil::html_element('pre', $capture['code'], $atts), $wp_content);
	}

	public static function test($wfw, $efr) {
		exit();
		return "123";
	}

}

// Only if WP is loaded and not in admin
if (defined('ABSPATH')) {
	if (!is_admin()) {
		register_activation_hook(__FILE__, 'CrayonWP::install');
		register_deactivation_hook(__FILE__, 'CrayonWP::uninstall');

		// Filters and Actions
		add_filter('init', 'CrayonWP::init');

		CrayonSettingsWP::load_settings(TRUE);
		if (CrayonGlobalSettings::val(CrayonSettings::MAIN_QUERY)) {
			add_action('wp', 'CrayonWP::wp', 100);
		} else {
			add_filter('the_posts', 'CrayonWP::the_posts', 100);
		}

		// XXX Some themes like to play with the content, make sure we replace after they're done
		add_filter('the_content', 'CrayonWP::the_content', 100);

		// Highlight bbPress content
		add_filter( 'bbp_get_reply_content', 'CrayonWP::highlight', 100);
		add_filter( 'bbp_get_topic_content', 'CrayonWP::highlight', 100);
		add_filter( 'bbp_get_forum_content', 'CrayonWP::highlight', 100);
		add_filter( 'bbp_get_topic_excerpt', 'CrayonWP::highlight', 100);

		if (CrayonGlobalSettings::val(CrayonSettings::COMMENTS)) {
			/* XXX This is called first to match Crayons, then higher priority replaces after other filters.
			 Prevents Crayon from being formatted by the filters, and also keeps original comment formatting. */
			add_filter('comment_text', 'CrayonWP::pre_comment_text', 1);
			add_filter('comment_text', 'CrayonWP::comment_text', 100);
		}

		// This ensures Crayons are not formatted by WP filters. Other plugins should specify priorities between 1 and 100.
		add_filter('get_the_excerpt', 'CrayonWP::pre_excerpt', 1);
		add_filter('get_the_excerpt', 'CrayonWP::post_get_excerpt', 100);
		add_filter('the_excerpt', 'CrayonWP::post_excerpt', 100);

		add_action('template_redirect', 'CrayonWP::wp_head');
	} else {
		// For marking a post as containing a Crayon
		add_action('save_post', 'CrayonWP::save_post', 10, 2);
		if (CrayonGlobalSettings::val(CrayonSettings::COMMENTS)) {
			//add_action('comment_post', 'CrayonWP::save_comment', 10, 2);
			add_action('edit_comment', 'CrayonWP::save_comment', 10, 2);
		}
	}
}

?>