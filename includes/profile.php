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
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TVARKOM_CSA_PROFILE_TOKEN', 'tvarkom_csa_profile_token' );
define( 'TVARKOM_CSA_PROFILE_RESULT', 'tvarkom_csa_profile_result' );
define( 'TVARKOM_CSA_PROFILE_HEADER', 'X-Tvarkom-Profile' );

/**
 * Profiler state for the one profiled request.
 */
final class Tvarkom_CSA_Profiler {
	/**
	 * Time (ns) spent in child callbacks, one entry per callback running.
	 *
	 * @var int[]
	 */
	public static $stack = array();

	/**
	 * Component key => [self ns, calls].
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	public static $components = array();

	/**
	 * "component\0hook" => [self ns, calls].
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	public static $hooks = array();

	/**
	 * Callbacks already wrapped: "hook\0priority\0id" => true.
	 *
	 * @var array<string, bool>
	 */
	public static $wrapped = array();

	/**
	 * Callback id => component key, or false for callbacks left alone.
	 *
	 * @var array<string, string|false>
	 */
	public static $callback_component = array();

	/**
	 * File => component key.
	 *
	 * @var array<string, string>
	 */
	public static $file_component = array();

	/**
	 * Request phase => seconds since WordPress started.
	 *
	 * @var array<string, float>
	 */
	public static $marks = array();

	/**
	 * Outgoing HTTP calls in progress: [start ns, url, component].
	 *
	 * @var array<int, array{0: int, 1: string, 2: string}>
	 */
	public static $http_open = array();

	/**
	 * Finished outgoing HTTP calls.
	 *
	 * @var array<int, array{host: string, own_site: bool, component: string, ms: float, status: int|null}>
	 */
	public static $http = array();

	/**
	 * Time (ns) spent wrapping callbacks.
	 *
	 * @var int
	 */
	public static $wrap_ns = 0;

	/**
	 * Measured cost (ns) of one wrapper call around an empty callback.
	 *
	 * @var float
	 */
	public static $call_cost_ns = 0.0;

	/**
	 * Product put in the temporary cart, 0 for none.
	 *
	 * @var int
	 */
	public static $product_id = 0;

	/**
	 * Base directories: type => normalized path with a trailing slash.
	 *
	 * @var array<string, string[]>
	 */
	public static $dirs = array();

	/**
	 * Adds a callback's own time (without the callbacks it triggered).
	 *
	 * @param string $component Component key.
	 * @param string $hook      Hook name.
	 * @param int    $ns        Self time.
	 */
	public static function record( $component, $hook, $ns ) {
		if ( isset( self::$components[ $component ] ) ) {
			self::$components[ $component ][0] += $ns;
			++self::$components[ $component ][1];
		} else {
			self::$components[ $component ] = array( $ns, 1 );
		}
		$key = $component . "\0" . $hook;
		if ( isset( self::$hooks[ $key ] ) ) {
			self::$hooks[ $key ][0] += $ns;
			++self::$hooks[ $key ][1];
		} else {
			self::$hooks[ $key ] = array( $ns, 1 );
		}
	}
}

/**
 * Starts the profiler if this request carries the token the audit job set a moment ago.
 * Called when the plugin file loads; costs one isset() on every other request.
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
 * @param array $data Custom query data.
 * @return array
 */
function tvarkom_csa_profile_tag_query( $data ) {
	$data                = is_array( $data ) ? $data : array();
	$data['tvarkom_csa'] = tvarkom_csa_profile_caller();
	return $data;
}

/**
 * Notes the start of an outgoing HTTP call (filter pre_http_request, passes $pre through).
 *
 * @param mixed  $pre  Short-circuit value.
 * @param array  $args Request arguments.
 * @param string $url  URL.
 * @return mixed
 */
function tvarkom_csa_profile_http_start( $pre, $args, $url ) {
	Tvarkom_CSA_Profiler::$http_open[] = array( hrtime( true ), (string) $url, tvarkom_csa_profile_caller() );
	return $pre;
}

/**
 * Records a finished outgoing HTTP call: host, time and status only (action http_api_debug).
 *
 * @param array|WP_Error $response  Response.
 * @param string         $context   "response".
 * @param string         $transport Transport class.
 * @param array          $args      Request arguments.
 * @param string         $url       URL.
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

/**
 * SQL with every value replaced by "?": no strings, numbers or comments, IN lists collapsed.
 *
 * @param string $sql Query.
 * @return string
 */
function tvarkom_csa_profile_query_shape( $sql ) {
	$sql = (string) preg_replace( '#/\*.*?\*/#s', '', $sql );
	$sql = (string) preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql );
	$sql = (string) preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/s', '?', $sql );
	$sql = (string) preg_replace( '/\b0x[0-9a-f]+\b/i', '?', $sql );
	$sql = (string) preg_replace( '/(?<![\w$])-?\d+(\.\d+)?(e[+-]?\d+)?\b/i', '?', $sql );
	$sql = (string) preg_replace( '/\(\s*\?(\s*,\s*\?)+\s*\)/', '(?)', $sql );
	$sql = trim( (string) preg_replace( '/\s+/', ' ', $sql ) );
	return substr( $sql, 0, 300 );
}

/**
 * Display name of a component key.
 *
 * @param string $key Component key.
 * @return string
 */
function tvarkom_csa_profile_component_name( $key ) {
	static $plugins    = null;
	list( $type, $id ) = array_pad( explode( ':', $key, 2 ), 2, '' );
	switch ( $type ) {
		case 'core':
			return 'WordPress core';
		case 'plugin':
			if ( null === $plugins ) {
				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$plugins = array();
				foreach ( get_plugins() as $file => $data ) {
					$plugins[ explode( '/', $file, 2 )[0] ] = $data['Name'];
				}
			}
			return isset( $plugins[ $id ] ) ? $plugins[ $id ] : $id;
		case 'theme':
			$theme = wp_get_theme( $id );
			return 'Theme: ' . ( $theme->exists() ? $theme->get( 'Name' ) : $id );
		case 'mu':
			return 'Must-use plugin: ' . $id;
		case 'dropin':
			return 'Drop-in: ' . $id;
		default:
			return 'Other code';
	}
}

/**
 * Builds the profile at the end of the request and leaves it for the audit job.
 */
function tvarkom_csa_profile_finish() {
	global $wpdb, $wp_object_cache;
	$total_ms  = round( tvarkom_csa_profile_elapsed() * 1000, 1 );
	$marks     = Tvarkom_CSA_Profiler::$marks;
	$at        = function ( $phase ) use ( $marks ) {
		return isset( $marks[ $phase ] ) ? round( $marks[ $phase ] * 1000, 1 ) : null;
	};
	$loaded    = $at( 'plugins_loaded' );
	$ready     = $at( 'wp_loaded' );
	$routed    = $at( 'template_redirect' );
	$phase_len = function ( $from, $to ) {
		return null === $from || null === $to ? null : round( max( 0, $to - $from ), 1 );
	};

	// Queries: per component, and the most expensive shapes.
	$components = array();
	$add        = function ( $key, $field, $value ) use ( &$components ) {
		if ( ! isset( $components[ $key ] ) ) {
			$components[ $key ] = array(
				'hook_ms'     => 0.0,
				'hook_calls'  => 0,
				'query_count' => 0,
				'query_ms'    => 0.0,
				'http_ms'     => 0.0,
			);
		}
		$components[ $key ][ $field ] += $value;
	};
	$shapes     = array();
	$query_ms   = 0.0;
	$exact      = array();
	$logged     = is_array( $wpdb->queries ) ? $wpdb->queries : array();
	foreach ( $logged as $q ) {
		$ms        = (float) $q[1] * 1000;
		$component = isset( $q[4]['tvarkom_csa'] ) ? (string) $q[4]['tvarkom_csa'] : 'core';
		$query_ms += $ms;
		$add( $component, 'query_count', 1 );
		$add( $component, 'query_ms', $ms );
		$shape = tvarkom_csa_profile_query_shape( (string) $q[0] );
		$id    = md5( $shape );
		if ( ! isset( $shapes[ $id ] ) ) {
			$shapes[ $id ] = array(
				'sql'       => $shape,
				'component' => $component,
				'count'     => 0,
				'total_ms'  => 0.0,
				'max_ms'    => 0.0,
				'identical' => 0,
			);
		}
		++$shapes[ $id ]['count'];
		$shapes[ $id ]['total_ms'] += $ms;
		$shapes[ $id ]['max_ms']    = max( $shapes[ $id ]['max_ms'], $ms );
		$exact_id                   = md5( (string) $q[0] );
		if ( isset( $exact[ $exact_id ] ) ) {
			++$shapes[ $id ]['identical'];
		}
		$exact[ $exact_id ] = true;
	}
	foreach ( Tvarkom_CSA_Profiler::$components as $key => $stats ) {
		$add( $key, 'hook_ms', $stats[0] / 1e6 );
		$add( $key, 'hook_calls', $stats[1] );
	}
	foreach ( Tvarkom_CSA_Profiler::$http as $call ) {
		$add( $call['component'], 'http_ms', $call['ms'] );
	}
	unset( $components['self'] );
	$components = array_filter(
		$components,
		function ( $c ) {
			return $c['hook_ms'] + $c['query_ms'] + $c['http_ms'] >= 0.5;
		}
	);

	uasort(
		$components,
		function ( $a, $b ) {
			return ( $b['hook_ms'] + $b['query_ms'] ) <=> ( $a['hook_ms'] + $a['query_ms'] );
		}
	);
	$component_rows = array();
	foreach ( array_slice( $components, 0, 15, true ) as $key => $c ) {
		$component_rows[] = array(
			'name'        => tvarkom_csa_profile_component_name( (string) $key ),
			'type'        => explode( ':', (string) $key, 2 )[0],
			'hook_ms'     => round( $c['hook_ms'], 1 ),
			'hook_calls'  => (int) $c['hook_calls'],
			'query_count' => (int) $c['query_count'],
			'query_ms'    => round( $c['query_ms'], 1 ),
			'http_ms'     => round( $c['http_ms'], 1 ),
		);
	}

	$hooks = Tvarkom_CSA_Profiler::$hooks;
	uasort(
		$hooks,
		function ( $a, $b ) {
			return $b[0] <=> $a[0];
		}
	);
	$hook_rows = array();
	foreach ( array_slice( $hooks, 0, 10, true ) as $key => $stats ) {
		list( $component, $hook ) = explode( "\0", (string) $key, 2 );
		$hook_rows[]              = array(
			'hook'      => $hook,
			'component' => tvarkom_csa_profile_component_name( $component ),
			'ms'        => round( $stats[0] / 1e6, 1 ),
			'calls'     => (int) $stats[1],
		);
	}

	uasort(
		$shapes,
		function ( $a, $b ) {
			return $b['total_ms'] <=> $a['total_ms'];
		}
	);
	$identical  = 0;
	$query_rows = array();
	foreach ( $shapes as $s ) {
		$identical += $s['identical'];
	}
	foreach ( array_slice( $shapes, 0, 10 ) as $s ) {
		$query_rows[] = array(
			'sql'       => $s['sql'],
			'component' => tvarkom_csa_profile_component_name( $s['component'] ),
			'count'     => $s['count'],
			'identical' => $s['identical'],
			'total_ms'  => round( $s['total_ms'], 1 ),
			'max_ms'    => round( $s['max_ms'], 1 ),
		);
	}

	$http_rows = array();
	foreach ( Tvarkom_CSA_Profiler::$http as $call ) {
		$call['component'] = tvarkom_csa_profile_component_name( $call['component'] );
		$http_rows[]       = $call;
	}

	$cache = array(
		'persistent' => (bool) wp_using_ext_object_cache(),
		'hits'       => is_object( $wp_object_cache ) && isset( $wp_object_cache->cache_hits ) && is_numeric( $wp_object_cache->cache_hits ) ? (int) $wp_object_cache->cache_hits : null,
		'misses'     => is_object( $wp_object_cache ) && isset( $wp_object_cache->cache_misses ) && is_numeric( $wp_object_cache->cache_misses ) ? (int) $wp_object_cache->cache_misses : null,
	);

	$calls = 0;
	foreach ( Tvarkom_CSA_Profiler::$components as $stats ) {
		$calls += $stats[1];
	}

	set_transient(
		TVARKOM_CSA_PROFILE_RESULT,
		array(
			'status'               => 'ok',
			'is_checkout'          => function_exists( 'is_checkout' ) && is_checkout(),
			'cart'                 => Tvarkom_CSA_Profiler::$product_id ? 'sample_product' : 'empty',
			'total_ms'             => $total_ms,
			'peak_memory_mb'       => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'phases'               => array(
				'load_ms'   => $loaded,
				'init_ms'   => $phase_len( $loaded, $ready ),
				'query_ms'  => $phase_len( $ready, $routed ),
				'render_ms' => $phase_len( $routed, $total_ms ),
			),
			'components'           => $component_rows,
			'slowest_hooks'        => $hook_rows,
			'queries'              => array(
				'total_count'       => (int) $wpdb->num_queries,
				'logged_count'      => count( $logged ),
				'logged_ms'         => round( $query_ms, 1 ),
				'identical_repeats' => $identical,
			),
			'slow_queries'         => $query_rows,
			'http_calls'           => $http_rows,
			'object_cache'         => $cache,
			'profiler_overhead_ms' => round( ( Tvarkom_CSA_Profiler::$wrap_ns + $calls * Tvarkom_CSA_Profiler::$call_cost_ns ) / 1e6, 1 ),
		),
		5 * MINUTE_IN_SECONDS
	);
}

/**
 * A published, in-stock simple product for the temporary cart: physical first,
 * so shipping is part of the profile. 0 if there's none.
 *
 * @return int
 */
function tvarkom_csa_profile_product() {
	foreach ( array( false, null ) as $virtual ) {
		$args = array(
			'status'       => 'publish',
			'type'         => 'simple',
			'stock_status' => 'instock',
			'visibility'   => 'visible',
			'limit'        => 10,
			'orderby'      => 'date',
			'order'        => 'DESC',
		);
		if ( null !== $virtual ) {
			$args['virtual'] = $virtual;
		}
		foreach ( wc_get_products( $args ) as $product ) {
			if ( $product->is_purchasable() && $product->is_in_stock() ) {
				return $product->get_id();
			}
		}
	}
	return 0;
}

/**
 * Runs the profiled checkout request and returns its result (or why there is none).
 * Called by the audit job on a Pro detailed audit.
 *
 * @return array<string, mixed>
 */
function tvarkom_csa_profile_checkout() {
	$token = wp_generate_password( 40, false, false );
	set_transient(
		TVARKOM_CSA_PROFILE_TOKEN,
		array(
			'token'      => $token,
			'product_id' => tvarkom_csa_profile_product(),
		),
		2 * MINUTE_IN_SECONDS
	);
	delete_transient( TVARKOM_CSA_PROFILE_RESULT );

	$start    = microtime( true );
	$response = wp_remote_get(
		// The extra argument keeps page caches from answering instead of WordPress.
		add_query_arg( 'tvarkom_profile', substr( md5( $token ), 0, 12 ), wc_get_checkout_url() ),
		array(
			'timeout'     => 30,
			'redirection' => 0,
			'user-agent'  => 'Tvarkom checkout profiler (+https://tvarkom.com)',
			'headers'     => array(
				TVARKOM_CSA_PROFILE_HEADER => $token,
				'Cache-Control'            => 'no-cache',
			),
			// Same as WordPress's own loopback checks: the request goes to this server.
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter.
		)
	);
	$wall_ms = round( ( microtime( true ) - $start ) * 1000, 1 );
	delete_transient( TVARKOM_CSA_PROFILE_TOKEN );
	$result = get_transient( TVARKOM_CSA_PROFILE_RESULT );
	delete_transient( TVARKOM_CSA_PROFILE_RESULT );

	$status = is_wp_error( $response ) ? null : (int) wp_remote_retrieve_response_code( $response );
	if ( ! is_array( $result ) ) {
		if ( null === $status ) {
			$reason = 'request_failed';
		} elseif ( $status >= 300 && $status < 400 ) {
			$reason = 'redirected';
		} else {
			$reason = 'not_profiled'; // Blocked, or answered by a cache before WordPress ran.
		}
		return array(
			'status'      => 'unavailable',
			'reason'      => $reason,
			'http_status' => $status,
		);
	}
	$result['http_status'] = $status;
	$result['wall_ms']     = $wall_ms;
	return $result;
}
