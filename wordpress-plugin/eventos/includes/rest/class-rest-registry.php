<?php
/**
 * Central REST API registry shared by every EventOS module.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Activity_Log;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One place where every EventOS REST endpoint is declared.
 *
 * The registry owns authentication, capability checks, nonce verification,
 * argument validation, response formatting, error handling and versioning, so
 * modules only supply a route and a callback.
 */
final class Rest_Registry {

	/**
	 * Default API version.
	 */
	public const DEFAULT_VERSION = 'v1';

	/**
	 * Vendor segment of the REST namespace.
	 */
	public const VENDOR = 'eventos';

	/**
	 * Registered endpoint definitions.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $endpoints = array();

	/**
	 * Whether the rest_api_init hook is attached.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Attach the registry to WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ), 20 );
	}

	/**
	 * Register a single endpoint.
	 *
	 * Accepted keys: route, methods, callback, capability, permission_callback,
	 * args, version, summary, description, module, envelope, log_action.
	 *
	 * @param array<string, mixed> $endpoint Endpoint definition.
	 * @param string               $module   Owning module slug.
	 * @return void
	 */
	public static function register( array $endpoint, string $module = 'core' ): void {
		if ( empty( $endpoint['route'] ) || empty( $endpoint['callback'] ) ) {
			return;
		}

		$endpoint = wp_parse_args(
			$endpoint,
			array(
				'methods'             => 'GET',
				'capability'          => '',
				'permission_callback' => null,
				'args'                => array(),
				'version'             => self::DEFAULT_VERSION,
				'summary'             => '',
				'description'         => '',
				'module'              => $module,
				'envelope'            => true,
				'log_action'          => '',
			)
		);

		$endpoint['route']   = '/' . ltrim( (string) $endpoint['route'], '/' );
		$endpoint['methods'] = strtoupper( implode( ',', (array) $endpoint['methods'] ) );

		self::$endpoints[] = $endpoint;
	}

	/**
	 * Register several endpoints at once.
	 *
	 * @param array<int, array<string, mixed>> $endpoints Endpoint definitions.
	 * @param string                           $module    Owning module slug.
	 * @return void
	 */
	public static function register_many( array $endpoints, string $module = 'core' ): void {
		foreach ( $endpoints as $endpoint ) {
			if ( is_array( $endpoint ) ) {
				self::register( $endpoint, $module );
			}
		}
	}

	/**
	 * Every registered endpoint definition.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function endpoints(): array {
		return self::$endpoints;
	}

	/**
	 * Fully qualified namespace for a version.
	 *
	 * @param string $version Version segment.
	 * @return string
	 */
	public static function rest_namespace( string $version = self::DEFAULT_VERSION ): string {
		return self::VENDOR . '/' . ( $version ? $version : self::DEFAULT_VERSION );
	}

	/**
	 * Hand every registered endpoint to WordPress.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		/**
		 * Last chance for modules to add endpoints before registration.
		 *
		 * @param string $registry Registry class name.
		 */
		do_action( 'eventos_register_rest_endpoints', __CLASS__ );

		$grouped = array();

		foreach ( self::$endpoints as $endpoint ) {
			$key               = self::rest_namespace( (string) $endpoint['version'] ) . '|' . $endpoint['route'];
			$grouped[ $key ][] = $endpoint;
		}

		foreach ( $grouped as $key => $definitions ) {
			list( $namespace, $route ) = explode( '|', $key, 2 );

			$args = array();

			foreach ( $definitions as $definition ) {
				$args[] = array(
					'methods'             => $definition['methods'],
					'args'                => (array) $definition['args'],
					'callback'            => self::wrap_callback( $definition ),
					'permission_callback' => self::wrap_permission( $definition ),
				);
			}

			register_rest_route( $namespace, $route, $args );
		}
	}

	/**
	 * Build the permission callback for an endpoint.
	 *
	 * @param array<string, mixed> $endpoint Endpoint definition.
	 * @return callable
	 */
	private static function wrap_permission( array $endpoint ): callable {
		return static function ( WP_REST_Request $request ) use ( $endpoint ) {
			$nonce = self::verify_nonce( $request );

			if ( is_wp_error( $nonce ) ) {
				return $nonce;
			}

			if ( is_callable( $endpoint['permission_callback'] ) ) {
				$allowed = call_user_func( $endpoint['permission_callback'], $request );

				if ( is_wp_error( $allowed ) ) {
					return $allowed;
				}

				if ( ! $allowed ) {
					return self::forbidden();
				}

				return true;
			}

			$capability = (string) $endpoint['capability'];

			if ( '' === $capability ) {
				return true;
			}

			if ( ! is_user_logged_in() ) {
				return new WP_Error(
					'eventos_not_authenticated',
					__( 'You must be signed in to use the EventOS API.', 'eventos' ),
					array( 'status' => 401 )
				);
			}

			return current_user_can( $capability ) ? true : self::forbidden();
		};
	}

	/**
	 * Standard forbidden error.
	 *
	 * @return WP_Error
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error(
			'eventos_forbidden',
			__( 'You are not allowed to perform this action.', 'eventos' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Verify the REST nonce when the client supplied one.
	 *
	 * WordPress rejects cookie authenticated write requests without a nonce on
	 * its own; this adds an explicit check so a stale or forged nonce never
	 * reaches a module callback, while leaving application password and OAuth
	 * clients (which send no nonce) untouched.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private static function verify_nonce( WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'x_wp_nonce' );

		if ( '' === $nonce ) {
			return true;
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'eventos_invalid_nonce',
				__( 'Your session expired. Reload the page and try again.', 'eventos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Wrap a module callback with error handling and response formatting.
	 *
	 * @param array<string, mixed> $endpoint Endpoint definition.
	 * @return callable
	 */
	private static function wrap_callback( array $endpoint ): callable {
		return static function ( WP_REST_Request $request ) use ( $endpoint ) {
			try {
				$result = call_user_func( $endpoint['callback'], $request );
			} catch ( Throwable $exception ) {
				return self::handle_exception( $exception, $endpoint );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $endpoint['log_action'] ) {
				Activity_Log::log(
					array(
						'action' => (string) $endpoint['log_action'],
						'module' => (string) $endpoint['module'],
						'entity' => (string) $endpoint['route'],
					)
				);
			}

			if ( $result instanceof WP_REST_Response ) {
				return $result;
			}

			if ( ! $endpoint['envelope'] ) {
				return rest_ensure_response( $result );
			}

			return Rest_Response::success( $result );
		};
	}

	/**
	 * Convert an uncaught exception into a REST error.
	 *
	 * @param Throwable            $exception Thrown exception.
	 * @param array<string, mixed> $endpoint  Endpoint definition.
	 * @return WP_Error
	 */
	private static function handle_exception( Throwable $exception, array $endpoint ): WP_Error {
		$code = $exception->getCode();
		$code = ( is_int( $code ) && $code >= 400 && $code < 600 ) ? $code : 500;

		Activity_Log::log(
			array(
				'action'   => 'rest_exception',
				'module'   => (string) $endpoint['module'],
				'entity'   => (string) $endpoint['route'],
				'severity' => Activity_Log::SEVERITY_ERROR,
				'context'  => array(
					'message' => $exception->getMessage(),
					'type'    => get_class( $exception ),
				),
			)
		);

		return new WP_Error(
			'eventos_server_error',
			$exception->getMessage(),
			array( 'status' => $code )
		);
	}
}
