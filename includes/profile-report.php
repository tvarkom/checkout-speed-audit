<?php
/**
 * Pro checkout profile, end of the profiled request: query shapes, component names, and the
 * result built from the profiler state and left in a transient for the audit (see profile.php).
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 *
 * @return void
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
