<?php
/**
 * Tweetstorm block and API helper.
 *
 * @package jetpack
 * @since 8.7.0
 */

use Automattic\Jetpack\Connection\Client;
use Automattic\Jetpack\Status;

/**
 * Class Jetpack_Tweetstorm_Helper
 *
 * @since 8.7.0
 */
class Jetpack_Tweetstorm_Helper {
	/**
	 * Blocks that can be converted to tweets.
	 *
	 * @var array
	 */
	private static $supported_blocks = array(
		'core/heading'   => array(
			'type'               => 'text',
			'content_attributes' => array( 'content' ),
			'template'           => '{{content}}',
			'force_new'          => true,
			'force_finished'     => false,
		),
		'core/image'     => array(
			'type'           => 'image',
			'url_attribute'  => 'url',
			'force_new'      => false,
			'force_finished' => true,
		),
		'core/list'      => array(
			'type'               => 'multiline',
			'multiline_tag'      => 'li',
			'content_attributes' => array( 'values' ),
			'template'           => '- {{line}}',
			'force_new'          => false,
			'force_finished'     => false,
		),
		'core/paragraph' => array(
			'type'               => 'text',
			'content_attributes' => array( 'content' ),
			'template'           => '{{content}}',
			'force_new'          => false,
			'force_finished'     => false,
		),
		'core/quote'     => array(
			'type'               => 'text',
			'content_attributes' => array( 'value', 'citation' ),
			'template'           => '“{{value}}” – {{citation}}',
			'force_new'          => false,
			'force_finished'     => false,
		),
		'core/verse'     => array(
			'type'               => 'text',
			'content_attributes' => array( 'content' ),
			'template'           => '{{content}}',
			'force_new'          => false,
			'force_finished'     => false,
		),
	);

	/**
	 * A cache of _wp_emoji_list( 'entities' ), after being run through html_entity_decode().
	 *
	 * Initialised in ::is_valid_tweet().
	 *
	 * @var array
	 */
	private static $emoji_list = array();

	/**
	 * Special line seperator character, for multiline text.
	 *
	 * @var string
	 */
	private static $line_seperator = "\xE2\x80\xA8";

	/**
	 * Gather the Tweetstorm.
	 *
	 * @param  string $url The tweet URL to gather from.
	 * @return mixed
	 */
	public static function gather( $url ) {
		if ( ( new Status() )->is_offline_mode() ) {
			return new WP_Error(
				'dev_mode',
				__( 'Tweet unrolling is not available in offline mode.', 'jetpack' )
			);
		}

		$site_id = self::get_site_id();
		if ( is_wp_error( $site_id ) ) {
			return $site_id;
		}

		if ( defined( 'IS_WPCOM' ) && IS_WPCOM ) {
			if ( ! class_exists( 'WPCOM_Gather_Tweetstorm' ) ) {
				\jetpack_require_lib( 'gather-tweetstorm' );
			}

			return WPCOM_Gather_Tweetstorm::gather( $url );
		}

		$response = Client::wpcom_json_api_request_as_blog(
			sprintf( '/sites/%d/tweetstorm/gather?url=%s', $site_id, rawurlencode( $url ) ),
			2,
			array( 'headers' => array( 'content-type' => 'application/json' ) ),
			null,
			'wpcom'
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new WP_Error( $data->code, $data->message, $data->data );
		}

		return $data;
	}

	/**
	 * Parse tweets.
	 *
	 * @param array $blocks An array of blocks that can be parsed into tweets.
	 * @return mixed
	 */
	public static function parse( $blocks ) {
		// Initialise the tweets array with an empty tweet, so we don't need to check
		// if we're creating the first tweet while processing blocks.
		$tweets = array();
		self::start_new_tweet( $tweets );

		foreach ( $blocks as $block ) {
			$block_def = self::$supported_blocks[ $block['name'] ];

			// Grab the most recent tweet, so we can append to that if we can.
			list( $current_tweet_index, $current_tweet ) = self::get_last_tweet( $tweets );

			// Check if we need to start a new tweet.
			if ( $current_tweet['finished'] || $block_def['force_new'] ) {
				list( $current_tweet_index, $current_tweet ) = self::start_new_tweet( $tweets );
			}

			// Handle media first, as we can most easily attach that to the previous tweet.
			if ( 'image' === $block_def['type'] ) {
				// If a URL hasn't been set, we can't use this block.
				if ( empty( $block['attributes'][ $block_def['url_attribute'] ] ) ) {
					continue;
				}

				$url = $block['attributes'][ $block_def['url_attribute'] ];

				// Check if we can add this image to the last tweet.
				if ( ! empty( $current_tweet['media'] ) ) {
					// There was already media attached to the last tweet,
					// so let's put it in a new tweet.
					list( $current_tweet_index, $current_tweet ) = self::start_new_tweet( $tweets );
				}

				$current_tweet['media'][] = array(
					'url' => $url,
				);

				self::save_tweet( $tweets, $current_tweet_index, $current_tweet, $block );
				continue;
			}

			$block_text = self::extract_text_from_block( $block );

			if ( 0 === strlen( $block_text ) ) {
				continue;
			}

			// If the entire block can't be fit in this tweet, we need to start a new tweet.
			if ( $current_tweet['changed'] && ! self::is_valid_tweet( trim( $current_tweet['text'] ) . "\n\n$block_text" ) ) {
				list( $current_tweet_index, $current_tweet ) = self::start_new_tweet( $tweets );
			}

			// Multiline blocks prioritise splitting by line, but are otherwise identical to
			// normal text blocks. This means we can treat normal text blocks as being
			// "multiline", but with a single line.
			if ( 'multiline' === $block_def['type'] ) {
				$lines = explode( self::$line_seperator, $block_text );
			} else {
				$lines = array( $block_text );
			}
			$line_total = count( $lines );

			// Keep track of how many characters from this block we've allocated to tweets.
			$current_character_count = 0;

			for ( $line_count = 0; $line_count < $line_total; $line_count++ ) {
				$line_text = $lines[ $line_count ];

				// If this is an empty line in a multiline block, we need to count the \n between
				// lines, but can otherwise skip this line.
				if ( 0 === strlen( $line_text ) && 'multiline' === $block_def['type'] ) {
					$current_character_count++;
					continue;
				}

				// Make sure we have the most recent tweet.
				list( $current_tweet_index, $current_tweet ) = self::get_last_tweet( $tweets );

				if ( $current_tweet['changed'] ) {
					// When it's the first line, add an extra blank line to seperate
					// the tweet text from that of the previous block.
					$seperator = "\n\n";
					if ( $line_count > 0 ) {
						$seperator = "\n";
					}

					// Is this line short enough to append to the current tweet?
					if ( self::is_valid_tweet( trim( $current_tweet['text'] ) . "$seperator$line_text" ) ) {
						// Don't trim the text yet, as we may need it for boundary calculations.
						$current_tweet['text'] = $current_tweet['text'] . "$seperator$line_text";

						self::save_tweet( $tweets, $current_tweet_index, $current_tweet, $block );
						continue;
					}

					// This line is too long, and lines *must* be split to a new tweet if they don't fit
					// into the current tweet. If this isn't the first line, record where we split the block.
					if ( $line_count > 0 ) {
						// Increment by 1 to allow for the \n between lines to be counted by ::get_boundary().
						$current_character_count  += strlen( $current_tweet['text'] ) + 1;
						$current_tweet['boundary'] = self::get_boundary( $block, $current_character_count );

						self::save_tweet( $tweets, $current_tweet_index, $current_tweet );
					}

					// Start a new tweet.
					list( $current_tweet_index, $current_tweet ) = self::start_new_tweet( $tweets );
				}

				// Since we're now at the start of a new tweet, is this line short enough to be a tweet by itself?
				if ( self::is_valid_tweet( $line_text ) ) {
					$current_tweet['text'] = $line_text;

					self::save_tweet( $tweets, $current_tweet_index, $current_tweet, $block );
					continue;
				}

				// The line is too long for a single tweet, so split it by sentences.
				$sentences      = preg_split( '/(?<!\.\.\.)(?<=[.?!]|\.\)|\.["\'])(\s+)(?=[a-zA-Z\'"\(])/', $line_text, -1, PREG_SPLIT_DELIM_CAPTURE );
				$sentence_total = count( $sentences );

				// preg_split() puts the blank space between sentences into a seperate entry in the result,
				// so we need to step through the result array by two, and append the blank space when needed.
				for ( $sentence_count = 0; $sentence_count < $sentence_total; $sentence_count += 2 ) {
					$current_sentence = $sentences[ $sentence_count ];
					if ( isset( $sentences[ $sentence_count + 1 ] ) ) {
						$current_sentence .= $sentences[ $sentence_count + 1 ];
					}

					// Make sure we have the most recent tweet.
					list( $current_tweet_index, $current_tweet ) = self::get_last_tweet( $tweets );

					// After the first sentence, we can try and append sentences to the previous sentence.
					if ( $current_tweet['changed'] && $sentence_count > 0 ) {
						// Is this sentence short enough for appending to the current tweet?
						if ( self::is_valid_tweet( $current_tweet['text'] . rtrim( $current_sentence ) ) ) {
							$current_tweet['text'] .= $current_sentence;

							self::save_tweet( $tweets, $current_tweet_index, $current_tweet, $block );
							continue;
						}
					}

					// Will this sentence fit in its own tweet?
					if ( self::is_valid_tweet( trim( $current_sentence ) ) ) {
						if ( $current_tweet['changed'] ) {
							// If we're already in the middle of a block, record the boundary
							// before creating a new tweet.
							if ( $line_count > 0 || $sentence_count > 0 ) {
								$current_character_count  += strlen( $current_tweet['text'] );
								$current_tweet['boundary'] = self::get_boundary( $block, $current_character_count );

								self::save_tweet( $tweets, $current_tweet_index, $current_tweet );
							}

							list( $current_tweet_index, $current_tweet ) = self::start_new_tweet( $tweets );
						}
						$current_tweet['text'] = $current_sentence;

						self::save_tweet( $tweets, $current_tweet_index, $current_tweet, $block );
						continue;
					}

					// This long sentence will start the next tweet that this block is going
					// to be turned into, so we need to record the boundary and start a new tweet.
					if ( $current_tweet['changed'] ) {
						$current_character_count  += strlen( $current_tweet['text'] );
						$current_tweet['boundary'] = self::get_boundary( $block, $current_character_count );

						self::save_tweet( $tweets, $current_tweet_index, $current_tweet );

						list( $current_tweet_index, $current_tweet ) = self::start_new_tweet( $tweets );
					}

					// Split the long sentence into words.
					$words      = explode( ' ', $current_sentence );
					$word_total = count( $words );
					for ( $word_count = 0; $word_count < $word_total; $word_count++ ) {
						// Make sure we have the most recent tweet.
						list( $current_tweet_index, $current_tweet ) = self::get_last_tweet( $tweets );

						// If we're on a new tweet, we don't want to add a space at the start.
						if ( ! $current_tweet['changed'] ) {
							$current_tweet['text'] = $words[ $word_count ];

							self::save_tweet( $tweets, $current_tweet_index, $current_tweet, $block );
							continue;
						}

						// Can we add this word to the current tweet?
						if ( self::is_valid_tweet( "{$current_tweet['text']} {$words[ $word_count ]}…" ) ) {
							$current_tweet['text'] .= " {$words[ $word_count ]}";

							self::save_tweet( $tweets, $current_tweet_index, $current_tweet, $block );
							continue;
						}

						// Add one for the space character that we won't include in the tweet text.
						$current_character_count += strlen( $current_tweet['text'] ) + 1;

						// We're starting a new tweet with this word. Append ellipsis to
						// the current tweet, then move on.
						$current_tweet['text'] .= '…';

						$current_tweet['boundary'] = self::get_boundary( $block, $current_character_count );
						self::save_tweet( $tweets, $current_tweet_index, $current_tweet );

						list( $current_tweet_index, $current_tweet ) = self::start_new_tweet( $tweets );

						// If this is the second tweet created by the split sentence, it'll start
						// with ellipsis, which we don't want to count, but we do want to count the space
						// that was replaced by this ellipsis.
						$current_tweet['text']    = "…{$words[ $word_count ]}";
						$current_character_count -= strlen( '…' );

						self::save_tweet( $tweets, $current_tweet_index, $current_tweet, $block );
					}
				}
			}
		}

		// We managed to get to the end without creating any tweets, so don't return the single empty tweet.
		if ( 1 === count( $tweets ) && false === $tweets[0]['changed'] ) {
			return array();
		}

		return array_map(
			function( $tweet ) {
				$tweet['text'] = trim( $tweet['text'] );
				$tweet['text'] = preg_replace( '/[ \t]+\n/', "\n", $tweet['text'] );

				return $tweet;
			},
			$tweets
		);
	}

	/**
	 * Get the last tweet in the array, along with the index for that tweet.
	 *
	 * @param array $tweets The array of tweets.
	 * @return array An array containing the index of the last tweet, and the last tweet itself.
	 */
	private static function get_last_tweet( $tweets ) {
		$tweet = end( $tweets );
		return array( key( $tweets ), $tweet );
	}

	/**
	 * Creates a blank tweet, appends it to the passed tweets array, and returns the tweet.
	 *
	 * @param array $tweets The array of tweets.
	 * @return array The blank tweet.
	 */
	private static function start_new_tweet( &$tweets ) {
		$tweets[] = array(
			// An array of blocks that make up this tweet.
			'blocks'   => array(),
			// If this tweet only contains part of a block, the boundary contains
			// information about where in the block the tweet ends.
			'boundary' => false,
			// The text content of the tweet.
			'text'     => '',
			// The media content of the tweet.
			'media'    => array(),
			// Some blocks force a hard finish to the tweet, even if subsequent blocks
			// could technically be appended. This flag shows when a tweet is finished.
			'finished' => false,
			// Flag if the current tweet already has content in it.
			'changed'  => false,
		);

		return self::get_last_tweet( $tweets );
	}

	/**
	 * Saves a tweet to the passed tweet array.
	 *
	 * This method adds some last minute checks: marking the tweet as "changed", as well
	 * as adding the $block to the tweet (if it was passed, and hasn't already been added).
	 *
	 * @param array $tweets      The array of tweets.
	 * @param int   $tweet_index Where in the tweet array this tweet should be stored.
	 * @param array $tweet       The tweet being stored.
	 * @param array $block       Optional. The block that was used to modify this tweet.
	 * @return array The saved tweet, after the last minute checks have been done.
	 */
	private static function save_tweet( &$tweets, $tweet_index, $tweet, $block = null ) {
		$tweet['changed'] = true;

		// Check if this block is already recorded against this tweet.
		if ( isset( $block ) ) {
			$block_def = self::$supported_blocks[ $block['name'] ];

			if ( $block_def['force_finished'] ) {
				$tweet['finished'] = true;
			}

			$last_block = end( $tweet['blocks'] );
			if ( false === $last_block || $last_block['clientId'] !== $block['clientId'] ) {
				$tweet['blocks'][] = $block;
			}
		}

		$tweets[ $tweet_index ] = $tweet;

		return $tweet;
	}

	/**
	 * Checks if the passed text is valid for a tweet or not.
	 *
	 * @param string $text The text to check.
	 * @return bool Whether or not the text is valid.
	 */
	private static function is_valid_tweet( $text ) {
		// Replace all multiline seperators with a \n, since that's the
		// character we actually want to count.
		$text = str_replace( self::$line_seperator, "\n", $text );

		// Keep a running total of characters we've removed.
		$stripped_characters = 0;

		// Since we use '…' a lot, strip it out, so we can still use the ASCII checks.
		$ellipsis_count = 0;
		$text           = str_replace( '…', '', $text, $ellipsis_count );

		// The ellipsis glyph counts for two characters.
		$stripped_characters += $ellipsis_count * 2;

		// Try filtering out emoji first, since ASCII text + emoji is a relatively common case.
		if ( ! self::is_ascii( $text ) ) {
			// Initialise the emoji cache.
			if ( 0 === count( self::$emoji_list ) ) {
				self::$emoji_list = array_map( 'html_entity_decode', _wp_emoji_list( 'entities' ) );
			}

			$emoji_count = 0;
			$text        = str_replace( self::$emoji_list, '', $text, $emoji_count );

			// Emoji graphemes count as 2 characters each.
			$stripped_characters += $emoji_count * 2;
		}

		if ( self::is_ascii( $text ) ) {
			$stripped_characters += strlen( $text );
			if ( $stripped_characters <= 280 ) {
				return true;
			}

			return false;
		}

		// Remove any glyphs that count as 1 character.
		// Source: https://github.com/twitter/twitter-text/blob/master/config/v3.json .
		$single_character_count = 0;
		$text                   = preg_replace( '/[\x{0000}-\x{4351}\x{8192}-\x{8205}\x{8208}-\x{8223}\x{8242}-\x{8247}]/uS', $text, -1, $single_character_count );

		$stripped_characters += $single_character_count;

		// Check if there's any text we haven't counted yet.
		// Any remaining glyphs count as 2 characters each.
		if ( 0 !== strlen( $text ) ) {
			// WP provides a compat version of mb_strlen(), no need to check if it exists.
			$stripped_characters += mb_strlen( $text, 'UTF-8' ) * 2;
		}

		if ( $stripped_characters <= 280 ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if a string only contains ASCII characters.
	 *
	 * @param string $text The string to check.
	 * @return bool Whether or not the string is ASCII-only.
	 */
	private static function is_ascii( $text ) {
		if ( function_exists( 'mb_check_encoding' ) ) {
			if ( mb_check_encoding( $text, 'ASCII' ) ) {
				return true;
			}
		} elseif ( ! preg_match( '/[^\x00-\x7F]/', $text ) ) {
			return true;
		}

		return false;
	}

	/**
	 * A block will generate a certain amount of text to be inserted into a tweet. If that text is too
	 * long for a tweet, we already know where the text will be split, but we need to calculate where
	 * that corresponds to in the block edit UI.
	 *
	 * The tweet template for that block may add extra characters, and the block may contain multiple
	 * RichText areas (corresponding to attributes), so we need to keep track of both until the
	 * this function calculates which attribute area (in the block editor, the richTextIdentifier)
	 * that offset corresponds to, and how far into that attribute area it is.
	 *
	 * @param array   $block  The block being checked.
	 * @param integer $offset The position in the tweet text where it will be split.
	 * @return array The position in the block editor to insert the tweet boundary annotation.
	 */
	private static function get_boundary( $block, $offset ) {
		$block_def = self::$supported_blocks[ $block['name'] ];

		$template_parts = preg_split( '/({{\w+}})/', self::$supported_blocks[ $block['name'] ]['template'], -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( 'multiline' === $block_def['type'] ) {
			$text_character_count     = 0;
			$text_code_unit_count     = 0;
			$template_character_count = 0;

			$lines = self::extract_multiline_block_lines( $block );

			$line_count = 0;
			foreach ( $lines as $line ) {
				$line_length = strlen( $line );

				if ( 0 !== $line_length ) {
					foreach ( $template_parts as $part ) {
						if ( '{{line}}' === $part ) {
							// Are we breaking in the middle of this line?
							if ( $text_character_count + $template_character_count + $line_length > $offset ) {
								// Calculate how far into this line the split defined by $offset occurs.
								$line_offset = $offset - $text_character_count - $template_character_count;
								$substr      = substr( $line, 0, $line_offset );
								$start       = self::utf_16_code_unit_length( $substr ) - 1 + $text_code_unit_count;
								return array(
									'start'     => $start,
									'end'       => $start + 1,
									'container' => $block_def['content_attributes'][0],
									'type'      => 'normal',
								);
							} else {
								$text_character_count += $line_length;
								$text_code_unit_count += self::utf_16_code_unit_length( $line );
							}
						} else {
							$template_character_count += strlen( $part );
						}
					}
				}

				// Are we breaking at the end of this line?
				if ( $text_character_count + $template_character_count + 1 === $offset ) {
					return array(
						'line'      => $line_count,
						'container' => $block_def['content_attributes'][0],
						'type'      => 'end-of-line',
					);
				}

				// Allow for the line break between lines.
				$text_character_count++;
				$text_code_unit_count++;
				$line_count++;
			}
		}

		$current_character_count = 0;

		foreach ( $template_parts as $part ) {
			$matches = array();
			if ( preg_match( '/{{(\w+)}}/', $part, $matches ) ) {
				$attribute_name   = $matches[1];
				$attribute_length = strlen( $block['attributes'][ $attribute_name ] );
				if ( $current_character_count + $attribute_length >= $offset ) {
					$attribute_offset = $offset - $current_character_count;
					$substr           = substr( $block['attributes'][ $attribute_name ], 0, $attribute_offset );
					$start            = self::utf_16_code_unit_length( $substr ) - 1;
					return array(
						'start'     => $start,
						'end'       => $start + 1,
						'container' => $attribute_name,
						'type'      => 'normal',
					);
				} else {
					$current_character_count += $attribute_length;
					continue;
				}
			} else {
				$current_character_count += strlen( $part );
			}
		}
	}

	/**
	 * JavaScript uses UTF-16 for encoding strings, which means we need to provide UTF-16
	 * based offsets for the block editor to render tweet boundaries in the correct location.
	 *
	 * UTF-16 is a variable-width character encoding: every code unit is 2 bytes, a single character
	 * can be one or two code units long. Fortunately for us, JavaScript's String.charAt() is based
	 * on the older UCS-2 character encoding, which only counts single code units. PHP's strlen()
	 * counts a code unit as being 2 characters, so once a string is converted to UTF-16, we have
	 * a fast way to determine how long it is in UTF-16 code units.
	 *
	 * @param string $text The natively encoded string to get the length of.
	 * @return int The length of the string in UTF-16 code units. Returns -1 if the length could not
	 *             be calculated.
	 */
	private static function utf_16_code_unit_length( $text ) {
		// If mb_convert_encoding() exists, we can use that for conversion.
		if ( function_exists( 'mb_convert_encoding' ) ) {
			// UTF-16 can add an additional code unit to the start of the string, called the
			// Byte Order Mark (BOM), which indicates whether the string is encoding as
			// big-endian, or little-endian. Since we don't want to count code unit, and the endianness
			// doesn't matter for our purposes, using PHP's UTF-16BE encoding uses big-endian
			// encoding, and ensures the BOM *won't* be prepended to the string to the string.
			return strlen( mb_convert_encoding( $text, 'UTF-16BE' ) ) / 2;
		}

		// If we can't convert this string, return a result that will avoid an incorrect annotation being added.
		return -1;
	}

	/**
	 * Extracts the tweetable text from a block.
	 *
	 * @param array $block The block, as represented in the block editor.
	 * @return string The tweetable text from the block, in the correct template form.
	 */
	private static function extract_text_from_block( $block ) {
		if ( ! isset( self::$supported_blocks[ $block['name'] ] ) ) {
			return '';
		}

		$block_def = self::$supported_blocks[ $block['name'] ];

		if ( 'text' === $block_def['type'] ) {
			$text = array_reduce(
				$block_def['content_attributes'],
				function( $current_text, $attribute ) use ( $block ) {
					return str_replace( '{{' . $attribute . '}}', $block['attributes'][ $attribute ], $current_text );
				},
				$block_def['template']
			);
		} elseif ( 'multiline' === $block_def['type'] ) {
			$lines = self::extract_multiline_block_lines( $block );
			$text  = '';

			foreach ( $lines as $line ) {
				if ( 0 === strlen( $line ) ) {
					$text .= self::$line_seperator;
				} else {
					$text .= str_replace( '{{line}}', $line, $block_def['template'] ) . self::$line_seperator;
				}
			}

			$text = trim( $text );
			$text = preg_replace( '/(' . self::$line_seperator . ')+$/', '', $text );
		}

		return wp_strip_all_tags( $text );
	}

	/**
	 * Given a multiline block, this will extract the text from each line, and return an array
	 * of those lines.
	 *
	 * @param array $block The block to extract from.
	 * @return array The array of lines.
	 */
	public static function extract_multiline_block_lines( $block ) {
		$block_def = self::$supported_blocks[ $block['name'] ];
		// Multiline blocks only support extracting content from one content attribute.
		$attribute = $block_def['content_attributes'][0];

		// Remove all HTML tags except the line wrapper tag, and remove attributes from that.
		$cleaned_content = wp_kses( $block['attributes'][ $attribute ], array( $block_def['multiline_tag'] => array() ) );

		// Split the content into an array, cleaning out unnecessary empty values and the wrapper tags.
		$tokens = wp_html_split( $cleaned_content );
		return array_values(
			array_filter(
				$tokens,
				function( $token, $key ) use ( $block_def, $tokens ) {
					// If the token is empty, and we're in the token after an opening
					// tag, we need to keep this, as it's an empty list item, so it affects
					// how we calculate line boundaries.
					if ( 0 === strlen( $token ) && $key > 0 && "<{$block_def['multiline_tag']}>" === $tokens[ $key - 1 ] ) {
						return true;
					}

					// Any other empty tokens can be removed.
					if ( 0 === strlen( $token ) ) {
						return false;
					}

					// Remove any remaining tags.
					if ( '<' === substr( $token, 0, 1 ) && '>' === substr( $token, -1 ) ) {
						return false;
					}

					return true;
				},
				ARRAY_FILTER_USE_BOTH
			)
		);
	}

	/**
	 * Get the WPCOM or self-hosted site ID.
	 *
	 * @return mixed
	 */
	public static function get_site_id() {
		$is_wpcom = ( defined( 'IS_WPCOM' ) && IS_WPCOM );
		$site_id  = $is_wpcom ? get_current_blog_id() : Jetpack_Options::get_option( 'id' );
		if ( ! $site_id ) {
			return new WP_Error(
				'unavailable_site_id',
				__( 'Sorry, something is wrong with your Jetpack connection.', 'jetpack' ),
				403
			);
		}
		return (int) $site_id;
	}
}
