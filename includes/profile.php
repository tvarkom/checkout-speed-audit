<?php
/**
 * Pro checkout profile: where the server's time goes on one checkout page load.
 *
 * The detailed audit job (tvarkom_csa_profile_checkout) requests this store's own
 * checkout page once, with a single-use token in a header. Only on that request,
 * the plugin times every hook callback and groups the time by plugin, logs the
 * database queries and outgoing HTTP calls, and reads the object cache's hit and
 * miss counts. A product is put in a temporary cart so WooCommerce renders the
 * real checkout instead of redirecting to the empty cart; that cart is never
 * saved and no cookie is set. Nothing is ordered. Every other request skips all
 * of this after one header check.
 *
 * What leaves the store: plugin and theme names, hook names, timings, counts,
 * query shapes with every value replaced by "?", and the host names of outgoing
 * calls. Never query values, URLs, cookies or customer data.
 *
 * This file is the profiler inside the profiled request. Its state is in
 * class-tvarkom-csa-profiler.php, the result it builds in profile-report.php, and
 * the audit side that requests the checkout in profile-request.php.
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVARKOM_CSA_PROFILE_TOKEN', 'tvarkom_csa_profile_token' );
define( 'TVARKOM_CSA_PROFILE_RESULT', 'tvarkom_csa_profile_result' );
define( 'TVARKOM_CSA_PROFILE_HEADER', 'X-Tvarkom-Profile' );

/**
 * Starts the profiler if this request carries the token the audit job set a moment ago.
 * Called when the plugin file loads; costs one isset() on every other request.
 *
 * @return void
 */
function tvarkom_csa_profile_maybe_start() {
	if ( empty( $_SERVER['HTTP_X_TVARKOM_PROFILE'] ) || ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
		return;
	}
	$sent    = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TVARKOM_PROFILE'] ) );
	$pending = get_transient( TVARKOM_CSA_PROFILE_TOKEN );
	if ( ! is_array( $pending ) || empty( $pending['token'] ) || ! hash_equals( (string) $pending['token'], $sent ) ) {
		return;
	}
	delete_transient( TVARKOM_CSA_PROFILE_TOKEN ); // Single use.
	Tvarkom_CSA_Profiler::$product_id = isset( $pending['product_id'] ) ? (int) $pending['product_id'] : 0;

	if ( ! defined( 'SAVEQUERIES' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core's query log switch; set only on the profiled request.
		define( 'SAVEQUERIES', true ); // wpdb checks it per query, so it applies from here on.
	}
	nocache_headers();

	$self = wp_normalize_path( dirname( __DIR__ ) ) . '/';
	$real = realpath( dirname( __DIR__ ) );

	Tvarkom_CSA_Profiler::$dirs = array(
		'self'   => array_unique( array( $self, false !== $real ? wp_normalize_path( $real ) . '/' : $self ) ),
		'plugin' => tvarkom_csa_profile_dir_variants( WP_PLUGIN_DIR ),
		'mu'     => tvarkom_csa_profile_dir_variants( WPMU_PLUGIN_DIR ),
		'theme'  => tvarkom_csa_profile_dir_variants( get_theme_root() ),
		'core'   => array_merge( tvarkom_csa_profile_dir_variants( ABSPATH . WPINC ), tvarkom_csa_profile_dir_variants( ABSPATH . 'wp-admin' ) ),
		'dropin' => tvarkom_csa_profile_dir_variants( WP_CONTENT_DIR ),
	);
	tvarkom_csa_profile_calibrate();

	// Callbacks added after a pass are picked up by the next one.
	tvarkom_csa_profile_wrap();
	add_action( 'plugins_loaded', 'tvarkom_csa_profile_wrap', PHP_INT_MAX );
	add_action( 'after_setup_theme', 'tvarkom_csa_profile_wrap', PHP_INT_MIN );
	add_action( 'init', 'tvarkom_csa_profile_wrap', PHP_INT_MAX );
	add_action( 'wp_loaded', 'tvarkom_csa_profile_wrap', PHP_INT_MAX );
	add_action( 'template_redirect', 'tvarkom_csa_profile_wrap', PHP_INT_MIN );
	add_action( 'wp_head', 'tvarkom_csa_profile_wrap', PHP_INT_MIN );

	foreach ( array( 'plugins_loaded', 'wp_loaded', 'template_redirect' ) as $phase ) {
		add_action(
			$phase,
			function () use ( $phase ) {
				Tvarkom_CSA_Profiler::$marks[ $phase ] = tvarkom_csa_profile_elapsed();
			},
			PHP_INT_MIN
		);
	}

	add_filter( 'log_query_custom_data', 'tvarkom_csa_profile_tag_query', 10, 1 );
	add_filter( 'pre_http_request', 'tvarkom_csa_profile_http_start', PHP_INT_MAX, 3 );
	add_action( 'http_api_debug', 'tvarkom_csa_profile_http_end', 10, 5 );
	add_action( 'wp_loaded', 'tvarkom_csa_profile_fill_cart', 30 );
	add_action( 'shutdown', 'tvarkom_csa_profile_finish', PHP_INT_MAX );
}

/**
 * A directory as normalized paths with a trailing slash, as given and resolved (symlinks).
 *
 * @param string $dir Directory.
 * @return string[]
 */
function tvarkom_csa_profile_dir_variants( $dir ) {
	$out  = array( trailingslashit( wp_normalize_path( $dir ) ) );
	$real = realpath( $dir );
	if ( false !== $real ) {
		$out[] = trailingslashit( wp_normalize_path( $real ) );
	}
	return array_values( array_unique( $out ) );
}

/**
 * Seconds since WordPress started loading.
 *
 * @return float
 */
function tvarkom_csa_profile_elapsed() {
	global $timestart;
	return microtime( true ) - (float) $timestart;
}

/**
 * Measures what one wrapped call costs, to report the profiler's own overhead.
 *
 * @return void
 */
function tvarkom_csa_profile_calibrate() {
	$wrapper = tvarkom_csa_profile_wrapper( '__return_null', 'self', 'calibrate' );
	$runs    = 200;
	$start   = hrtime( true );
	for ( $i = 0; $i < $runs; $i++ ) {
		$wrapper();
	}
	Tvarkom_CSA_Profiler::$call_cost_ns = ( hrtime( true ) - $start ) / $runs;
	Tvarkom_CSA_Profiler::$components   = array();
	Tvarkom_CSA_Profiler::$hooks        = array();
}

/**
 * A callable that runs $callback and records its own time for $component.
 *
 * @param callable $callback  Original callback.
 * @param string   $component Component key.
 * @param string   $hook      Hook name.
 * @return Closure
 */
function tvarkom_csa_profile_wrapper( $callback, $component, $hook ) {
	return static function ( ...$args ) use ( $callback, $component, $hook ) {
		Tvarkom_CSA_Profiler::$stack[] = 0;
		$start                         = hrtime( true );
		try {
			return call_user_func_array( $callback, $args );
		} finally {
			$elapsed  = hrtime( true ) - $start;
			$children = (int) array_pop( Tvarkom_CSA_Profiler::$stack );
			$depth    = count( Tvarkom_CSA_Profiler::$stack );
			if ( $depth > 0 ) {
				Tvarkom_CSA_Profiler::$stack[ $depth - 1 ] += $elapsed;
			}
			Tvarkom_CSA_Profiler::record( $component, $hook, $elapsed - $children );
		}
	};
}

/**
 * Wraps every hook callback not wrapped yet. The callback keeps its key in the
 * hook, so remove_action() and has_filter() by other plugins still find it.
 * Callbacks taking arguments by reference are left alone (a wrapper would break them).
 *
 * @return void
 */
function tvarkom_csa_profile_wrap() {
	global $wp_filter;
	$start = hrtime( true );
	foreach ( $wp_filter as $tag => $hook ) {
		if ( ! $hook instanceof WP_Hook || 'all' === $tag ) {
			continue;
		}
		$name = tvarkom_csa_profile_hook_name( (string) $tag );
		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback ) {
				$key = $tag . "\0" . $priority . "\0" . $id;
				if ( isset( Tvarkom_CSA_Profiler::$wrapped[ $key ] ) ) {
					continue;
				}
				Tvarkom_CSA_Profiler::$wrapped[ $key ] = true;
				if ( ! isset( Tvarkom_CSA_Profiler::$callback_component[ $id ] ) ) {
					Tvarkom_CSA_Profiler::$callback_component[ $id ] = tvarkom_csa_profile_callback_component( $callback['function'] );
				}
				$component = Tvarkom_CSA_Profiler::$callback_component[ $id ];
				if ( false === $component || 'self' === $component ) {
					continue;
				}
				$hook->callbacks[ $priority ][ $id ]['function'] = tvarkom_csa_profile_wrapper( $callback['function'], $component, $name );
			}
		}
	}
	Tvarkom_CSA_Profiler::$wrap_ns += hrtime( true ) - $start;
}

/**
 * Hook name as sent: printable ASCII, at most 120 characters.
 *
 * @param string $tag Hook name.
 * @return string
 */
function tvarkom_csa_profile_hook_name( $tag ) {
	return substr( (string) preg_replace( '/[^\x21-\x7e]/', '?', $tag ), 0, 120 );
}

/**
 * Component key of a callback from the file that defines it, or false to leave
 * it unwrapped (unknown callable or arguments by reference).
 *
 * @param mixed $callback Callback.
 * @return string|false
 */
function tvarkom_csa_profile_callback_component( $callback ) {
	try {
		if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
			$callback = explode( '::', $callback, 2 );
		}
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$ref = new ReflectionMethod( $callback[0], $callback[1] );
		} elseif ( $callback instanceof Closure || is_string( $callback ) ) {
			$ref = new ReflectionFunction( $callback );
		} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
			$ref = new ReflectionMethod( $callback, '__invoke' );
		} else {
			return false;
		}
	} catch ( ReflectionException $e ) {
		return false; // E.g. a method handled by __call().
	}
	foreach ( $ref->getParameters() as $param ) {
		if ( $param->isPassedByReference() ) {
			return false;
		}
	}
	$file = $ref->getFileName();
	return false === $file ? 'core' : tvarkom_csa_profile_file_component( $file ); // Internal PHP functions count as core.
}

/**
 * Component key of a file: "plugin:<dir>", "mu:<name>", "theme:<dir>", "dropin:<file>", "core", "self" or "other".
 *
 * @param string $file Absolute path.
 * @return string
 */
function tvarkom_csa_profile_file_component( $file ) {
	if ( isset( Tvarkom_CSA_Profiler::$file_component[ $file ] ) ) {
		return Tvarkom_CSA_Profiler::$file_component[ $file ];
	}
	$path      = wp_normalize_path( $file );
	$component = 'other';
	if ( in_array( trailingslashit( dirname( $path ) ), tvarkom_csa_profile_dir_variants( ABSPATH ), true ) && preg_match( '/^(wp-[\w-]+|index)\.php$/', basename( $path ) ) ) {
		$component = 'core'; // index.php, wp-blog-header.php, wp-load.php and the like.
	}
	foreach ( 'core' === $component ? array() : array( 'self', 'mu', 'plugin', 'theme', 'core', 'dropin' ) as $type ) {
		foreach ( Tvarkom_CSA_Profiler::$dirs[ $type ] as $dir ) {
			if ( 0 !== strpos( $path, $dir ) ) {
				continue;
			}
			$first = explode( '/', substr( $path, strlen( $dir ) ), 2 )[0];
			switch ( $type ) {
				case 'self':
				case 'core':
					$component = $type;
					break;
				case 'dropin':
					$component = false === strpos( substr( $path, strlen( $dir ) ), '/' ) ? 'dropin:' . $first : 'other';
					break;
				default:
					$component = $type . ':' . $first;
			}
			break 2;
		}
	}
	Tvarkom_CSA_Profiler::$file_component[ $file ] = $component;
	return $component;
}

/**
 * The component that caused the current call: the first plugin, theme or other
 * non-core file on the call stack, else core. For queries, the stack is read
 * from the outermost $wpdb call, so a database drop-in (db.php) isn't blamed.
 *
 * @return string
 */
function tvarkom_csa_profile_caller() {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- only on the profiled request.
	$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 40 );
	for ( $i = count( $frames ) - 1; $i > 0; $i-- ) {
		if ( isset( $frames[ $i ]['class'] ) && is_a( $frames[ $i ]['class'], 'wpdb', true ) ) {
			$frames = array_slice( $frames, $i ); // Its 'file' is the code that called $wpdb.
			break;
		}
	}
	foreach ( $frames as $frame ) {
		if ( empty( $frame['file'] ) ) {
			continue;
		}
		$component = tvarkom_csa_profile_file_component( $frame['file'] );
		if ( 'core' !== $component && 'self' !== $component ) {
			return $component;
		}
	}
	return 'core';
}

/**
 * Tags each logged query with the component that ran it (filter log_query_custom_data).
 *
 * @param mixed $data Custom query data (an array, unless another filter broke it).
 * @return array<string, mixed>
 */
function tvarkom_csa_profile_tag_query( $data ) {
	$data                = is_array( $data ) ? $data : array();
	$data['tvarkom_csa'] = tvarkom_csa_profile_caller();
	return $data;
}

/**
 * Notes the start of an outgoing HTTP call (filter pre_http_request, passes $pre through).
 *
 * @param mixed                $pre  Short-circuit value.
 * @param array<string, mixed> $args Request arguments.
 * @param string               $url  URL.
 * @return mixed
 */
function tvarkom_csa_profile_http_start( $pre, $args, $url ) {
	Tvarkom_CSA_Profiler::$http_open[] = array( hrtime( true ), (string) $url, tvarkom_csa_profile_caller() );
	return $pre;
}

/**
 * Records a finished outgoing HTTP call: host, time and status only (action http_api_debug).
 *
 * @param array<string, mixed>|WP_Error $response  Response.
 * @param string                        $context   "response".
 * @param string                        $transport Transport class.
 * @param array<string, mixed>          $args      Request arguments.
 * @param string                        $url       URL.
 * @return void
 */
function tvarkom_csa_profile_http_end( $response, $context, $transport, $args, $url ) {
	for ( $i = count( Tvarkom_CSA_Profiler::$http_open ) - 1; $i >= 0; $i-- ) {
		if ( Tvarkom_CSA_Profiler::$http_open[ $i ][1] !== (string) $url ) {
			continue;
		}
		list( $start, , $component ) = Tvarkom_CSA_Profiler::$http_open[ $i ];
		array_splice( Tvarkom_CSA_Profiler::$http_open, $i, 1 );
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		$host = is_string( $host ) ? strtolower( $host ) : 'unknown';
		$own  = array( 'localhost', '127.0.0.1', '::1', '[::1]', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ) );
		if ( count( Tvarkom_CSA_Profiler::$http ) < 25 ) {
			Tvarkom_CSA_Profiler::$http[] = array(
				'host'      => $host,
				'own_site'  => in_array( $host, $own, true ), // E.g. WordPress starting WP-Cron.
				'component' => $component,
				'ms'        => round( ( hrtime( true ) - $start ) / 1e6, 1 ),
				'status'    => is_wp_error( $response ) ? null : (int) wp_remote_retrieve_response_code( $response ),
			);
		}
		return;
	}
}

/**
 * Puts the chosen product in a cart that exists only for this request, so the
 * real checkout renders. No add-to-cart actions run, no cookie is set and the
 * session isn't saved.
 *
 * @return void
 */
function tvarkom_csa_profile_fill_cart() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->cart->is_empty() ) {
		return;
	}
	add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
	if ( WC()->session ) {
		remove_action( 'shutdown', array( WC()->session, 'save_data' ), 20 );
	}
	$product = Tvarkom_CSA_Profiler::$product_id ? wc_get_product( Tvarkom_CSA_Profiler::$product_id ) : null;
	if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		Tvarkom_CSA_Profiler::$product_id = 0;
		add_filter( 'woocommerce_checkout_redirect_empty_cart', '__return_false' ); // Profile the empty checkout instead.
		return;
	}
	$key = WC()->cart->generate_cart_id( $product->get_id() );
	WC()->cart->set_cart_contents(
		array(
			$key => array(
				'key'          => $key,
				'product_id'   => $product->get_id(),
				'variation_id' => 0,
				'variation'    => array(),
				'quantity'     => 1,
				'data'         => $product,
				'data_hash'    => wc_get_cart_item_data_hash( $product ),
			),
		)
	);
}
