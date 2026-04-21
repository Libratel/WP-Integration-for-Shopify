<?php
/**
 * Module registry for plugin lifecycle orchestration.
 *
 * @package PortalIdentityBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and boots plugin modules in deterministic order.
 */
final class PIB_Module_Registry {
	/**
	 * Registered module definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $definitions = array();

	/**
	 * Booted module instances.
	 *
	 * @var array<string, mixed>
	 */
	private $instances = array();

	/**
	 * Whether module boot has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Register module by callable factory.
	 *
	 * Factory receives: ($plugin, $module_id, $registry).
	 *
	 * @param string   $module_id Module identifier.
	 * @param callable $factory   Module factory.
	 * @return bool
	 */
	public function register( $module_id, $factory ) {
		if ( $this->booted || ! is_callable( $factory ) ) {
			return false;
		}

		$module_id = $this->sanitize_module_id( $module_id );
		if ( '' === $module_id ) {
			return false;
		}

		$this->definitions[ $module_id ] = array(
			'factory' => $factory,
		);

		return true;
	}

	/**
	 * Register module backed by static singleton accessor.
	 *
	 * Class must expose: ClassName::instance( PIB_Plugin $plugin )
	 *
	 * @param string $module_id  Module identifier.
	 * @param string $class_name Class name.
	 * @return bool
	 */
	public function register_class( $module_id, $class_name ) {
		$class_name = ltrim( (string) $class_name, '\\' );
		if ( '' === $class_name ) {
			return false;
		}

		return $this->register(
			$module_id,
			function( PIB_Plugin $plugin ) use ( $class_name ) {
				if ( ! class_exists( $class_name ) || ! is_callable( array( $class_name, 'instance' ) ) ) {
					return null;
				}

				return call_user_func( array( $class_name, 'instance' ), $plugin );
			}
		);
	}

	/**
	 * Remove a module before boot.
	 *
	 * @param string $module_id Module identifier.
	 * @return bool
	 */
	public function unregister( $module_id ) {
		if ( $this->booted ) {
			return false;
		}

		$module_id = $this->sanitize_module_id( $module_id );
		if ( '' === $module_id || ! isset( $this->definitions[ $module_id ] ) ) {
			return false;
		}

		unset( $this->definitions[ $module_id ] );
		return true;
	}

	/**
	 * Check if module definition exists.
	 *
	 * @param string $module_id Module identifier.
	 * @return bool
	 */
	public function has( $module_id ) {
		$module_id = $this->sanitize_module_id( $module_id );
		return '' !== $module_id && isset( $this->definitions[ $module_id ] );
	}

	/**
	 * Boot all registered modules.
	 *
	 * @param PIB_Plugin $plugin Plugin container.
	 * @return array<string, mixed>
	 */
	public function boot( PIB_Plugin $plugin ) {
		if ( $this->booted ) {
			return $this->instances;
		}

		// Freeze registry mutations while factories are executing.
		$this->booted = true;

		foreach ( $this->definitions as $module_id => $definition ) {
			$instance = call_user_func( $definition['factory'], $plugin, $module_id, $this );
			if ( null === $instance ) {
				continue;
			}

			$this->instances[ $module_id ] = $instance;

			/**
			 * Fires when an individual module has booted.
			 *
			 * @param string              $module_id Module ID.
			 * @param mixed               $instance  Module instance.
			 * @param PIB_Plugin          $plugin    Plugin container.
			 * @param PIB_Module_Registry $registry  Module registry.
			 */
			do_action( 'pib_module_booted', $module_id, $instance, $plugin, $this );
		}

		return $this->instances;
	}

	/**
	 * Get module instance by ID.
	 *
	 * @param string $module_id Module identifier.
	 * @return mixed|null
	 */
	public function get( $module_id ) {
		$module_id = $this->sanitize_module_id( $module_id );
		if ( '' === $module_id || ! isset( $this->instances[ $module_id ] ) ) {
			return null;
		}

		return $this->instances[ $module_id ];
	}

	/**
	 * Return all booted module instances.
	 *
	 * @return array<string, mixed>
	 */
	public function all() {
		return $this->instances;
	}

	/**
	 * Return all registered module identifiers in boot order.
	 *
	 * @return array<int, string>
	 */
	public function all_module_ids() {
		return array_keys( $this->definitions );
	}

	/**
	 * Whether boot has completed.
	 *
	 * @return bool
	 */
	public function is_booted() {
		return $this->booted;
	}

	/**
	 * Normalize module identifier.
	 *
	 * @param string $module_id Raw module identifier.
	 * @return string
	 */
	private function sanitize_module_id( $module_id ) {
		return sanitize_key( (string) $module_id );
	}
}
