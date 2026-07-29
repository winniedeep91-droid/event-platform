<?php
/**
 * Self documenting REST API reference.
 *
 * @package EventOS
 */

declare( strict_types = 1 );

namespace EventOS\Rest;

use EventOS\Permissions;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds an OpenAPI 3.1 document from the endpoints modules registered.
 *
 * Because every module declares its routes through Rest_Registry, the reference
 * is always in sync with what the platform actually serves.
 */
final class Docs_Controller {

	/**
	 * Human readable endpoint list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	public static function index( WP_REST_Request $request ): array {
		$module    = sanitize_key( (string) $request->get_param( 'module' ) );
		$endpoints = array();

		foreach ( Rest_Registry::endpoints() as $endpoint ) {
			if ( $module && $module !== (string) $endpoint['module'] ) {
				continue;
			}

			$endpoints[] = array(
				'module'      => (string) $endpoint['module'],
				'version'     => (string) $endpoint['version'],
				'namespace'   => Rest_Registry::rest_namespace( (string) $endpoint['version'] ),
				'route'       => (string) $endpoint['route'],
				'url'         => rest_url( Rest_Registry::rest_namespace( (string) $endpoint['version'] ) . $endpoint['route'] ),
				'methods'     => explode( ',', (string) $endpoint['methods'] ),
				'capability'  => (string) $endpoint['capability'],
				'summary'     => (string) $endpoint['summary'],
				'description' => (string) $endpoint['description'],
				'envelope'    => (bool) $endpoint['envelope'],
				'parameters'  => self::parameters( (array) $endpoint['args'] ),
			);
		}

		usort(
			$endpoints,
			static function ( array $a, array $b ): int {
				return array( $a['module'], $a['route'] ) <=> array( $b['module'], $b['route'] );
			}
		);

		return array(
			'name'        => get_bloginfo( 'name' ) . ' — EventOS API',
			'version'     => EVENTOS_VERSION,
			'base_url'    => rest_url( Rest_Registry::rest_namespace() ),
			'openapi_url' => rest_url( Rest_Registry::rest_namespace() . '/docs/openapi' ),
			'auth'        => array(
				'cookie'               => __( 'Send the X-WP-Nonce header with the wp_rest nonce.', 'eventos' ),
				'application_password' => __( 'Send an HTTP Basic Authorization header with a WordPress application password.', 'eventos' ),
			),
			'envelope'    => array(
				'success' => true,
				'data'    => null,
				'meta'    => new \stdClass(),
			),
			'total'       => count( $endpoints ),
			'endpoints'   => $endpoints,
		);
	}

	/**
	 * OpenAPI 3.1 document.
	 *
	 * @return array<string, mixed>
	 */
	public static function openapi(): array {
		$paths   = array();
		$schemes = array();

		foreach ( Rest_Registry::endpoints() as $endpoint ) {
			$namespace = Rest_Registry::rest_namespace( (string) $endpoint['version'] );
			$path      = '/' . $namespace . $endpoint['route'];

			foreach ( explode( ',', (string) $endpoint['methods'] ) as $method ) {
				$method = strtolower( trim( $method ) );

				if ( '' === $method ) {
					continue;
				}

				$operation = array(
					'tags'        => array( (string) $endpoint['module'] ),
					'summary'     => (string) $endpoint['summary'],
					'description' => self::describe( $endpoint ),
					'operationId' => self::operation_id( $method, $path ),
					'security'    => array( array( 'cookieAuth' => array() ), array( 'basicAuth' => array() ) ),
					'responses'   => array(
						'200' => array(
							'description' => __( 'Successful response.', 'eventos' ),
							'content'     => array(
								'application/json' => array(
									'schema' => array( '$ref' => '#/components/schemas/Envelope' ),
								),
							),
						),
						'403' => array(
							'description' => __( 'The current user lacks the required capability.', 'eventos' ),
							'content'     => array(
								'application/json' => array(
									'schema' => array( '$ref' => '#/components/schemas/Error' ),
								),
							),
						),
					),
				);

				if ( in_array( $method, array( 'get', 'delete' ), true ) ) {
					$operation['parameters'] = self::openapi_parameters( (array) $endpoint['args'], $path );
				} else {
					$operation['parameters']  = self::openapi_parameters( array(), $path );
					$operation['requestBody'] = array(
						'required' => (bool) self::has_required( (array) $endpoint['args'] ),
						'content'  => array(
							'application/json' => array(
								'schema' => self::body_schema( (array) $endpoint['args'] ),
							),
						),
					);
				}

				$paths[ $path ][ $method ] = $operation;
			}

			$schemes[ (string) $endpoint['module'] ] = true;
		}

		ksort( $paths );

		return array(
			'openapi'    => '3.1.0',
			'info'       => array(
				'title'       => get_bloginfo( 'name' ) . ' — EventOS API',
				'version'     => EVENTOS_VERSION,
				'description' => __( 'Generated from the EventOS REST registry. Every registered module contributes its own endpoints.', 'eventos' ),
			),
			'servers'    => array( array( 'url' => untrailingslashit( rest_url() ) ) ),
			'tags'       => array_map(
				static fn( string $module ): array => array( 'name' => $module ),
				array_keys( $schemes )
			),
			'paths'      => $paths,
			'components' => array(
				'securitySchemes' => array(
					'cookieAuth' => array(
						'type'        => 'apiKey',
						'in'          => 'header',
						'name'        => 'X-WP-Nonce',
						'description' => __( 'WordPress cookie authentication with a wp_rest nonce.', 'eventos' ),
					),
					'basicAuth'  => array(
						'type'   => 'http',
						'scheme' => 'basic',
					),
				),
				'schemas'         => array(
					'Envelope' => array(
						'type'       => 'object',
						'properties' => array(
							'success' => array( 'type' => 'boolean' ),
							'data'    => array( 'description' => __( 'Endpoint payload.', 'eventos' ) ),
							'meta'    => array( 'type' => 'object' ),
						),
					),
					'Error'    => array(
						'type'       => 'object',
						'properties' => array(
							'code'    => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
							'data'    => array(
								'type'       => 'object',
								'properties' => array( 'status' => array( 'type' => 'integer' ) ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Endpoint description including the capability requirement.
	 *
	 * @param array<string, mixed> $endpoint Endpoint definition.
	 * @return string
	 */
	private static function describe( array $endpoint ): string {
		$description = (string) $endpoint['description'];
		$capability  = (string) $endpoint['capability'];

		if ( '' === $capability ) {
			return $description;
		}

		$labels = Permissions::capabilities();
		$label  = (string) ( $labels[ $capability ] ?? $capability );

		return trim(
			$description . "\n\n" . sprintf(
				/* translators: 1: capability label, 2: capability slug. */
				__( 'Requires the "%1$s" capability (%2$s).', 'eventos' ),
				$label,
				$capability
			)
		);
	}

	/**
	 * Flatten registered args for the readable reference.
	 *
	 * @param array<string, mixed> $args Argument definitions.
	 * @return array<int, array<string, mixed>>
	 */
	private static function parameters( array $args ): array {
		$parameters = array();

		foreach ( $args as $name => $definition ) {
			$definition = (array) $definition;

			$parameters[] = array(
				'name'        => (string) $name,
				'type'        => (string) ( $definition['type'] ?? 'string' ),
				'required'    => ! empty( $definition['required'] ),
				'default'     => $definition['default'] ?? null,
				'enum'        => array_values( (array) ( $definition['enum'] ?? array() ) ),
				'description' => (string) ( $definition['description'] ?? '' ),
			);
		}

		return $parameters;
	}

	/**
	 * OpenAPI parameter objects, including path placeholders.
	 *
	 * @param array<string, mixed> $args Argument definitions.
	 * @param string               $path Route path.
	 * @return array<int, array<string, mixed>>
	 */
	private static function openapi_parameters( array $args, string $path ): array {
		$parameters = array();

		if ( preg_match_all( '/\(\?P<([a-zA-Z0-9_]+)>/', $path, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$parameters[] = array(
					'name'     => $name,
					'in'       => 'path',
					'required' => true,
					'schema'   => array( 'type' => 'string' ),
				);
			}
		}

		foreach ( $args as $name => $definition ) {
			$definition = (array) $definition;

			$parameters[] = array(
				'name'        => (string) $name,
				'in'          => 'query',
				'required'    => ! empty( $definition['required'] ),
				'description' => (string) ( $definition['description'] ?? '' ),
				'schema'      => self::schema( $definition ),
			);
		}

		return $parameters;
	}

	/**
	 * Request body schema built from registered args.
	 *
	 * @param array<string, mixed> $args Argument definitions.
	 * @return array<string, mixed>
	 */
	private static function body_schema( array $args ): array {
		$properties = array();
		$required   = array();

		foreach ( $args as $name => $definition ) {
			$definition             = (array) $definition;
			$properties[ (string) $name ] = self::schema( $definition );

			if ( ! empty( $definition['required'] ) ) {
				$required[] = (string) $name;
			}
		}

		$schema = array(
			'type'       => 'object',
			'properties' => (object) $properties,
		);

		if ( $required ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Convert a WordPress arg definition into a JSON schema fragment.
	 *
	 * @param array<string, mixed> $definition Argument definition.
	 * @return array<string, mixed>
	 */
	private static function schema( array $definition ): array {
		$schema = array( 'type' => (string) ( $definition['type'] ?? 'string' ) );

		if ( isset( $definition['default'] ) ) {
			$schema['default'] = $definition['default'];
		}

		if ( ! empty( $definition['enum'] ) ) {
			$schema['enum'] = array_values( (array) $definition['enum'] );
		}

		if ( isset( $definition['minimum'] ) ) {
			$schema['minimum'] = $definition['minimum'];
		}

		if ( isset( $definition['maximum'] ) ) {
			$schema['maximum'] = $definition['maximum'];
		}

		return $schema;
	}

	/**
	 * Whether any argument is required.
	 *
	 * @param array<string, mixed> $args Argument definitions.
	 * @return bool
	 */
	private static function has_required( array $args ): bool {
		foreach ( $args as $definition ) {
			if ( ! empty( ( (array) $definition )['required'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Deterministic operation identifier.
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route path.
	 * @return string
	 */
	private static function operation_id( string $method, string $path ): string {
		$slug = preg_replace( '/[^a-zA-Z0-9]+/', '_', $path ) ?? '';

		return $method . '_' . trim( (string) $slug, '_' );
	}
}
