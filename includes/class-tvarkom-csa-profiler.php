<?php
/**
 * Profiler state for the one profiled checkout request (see profile.php).
 *
 * @package CheckoutSpeedAudit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * @return void
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
