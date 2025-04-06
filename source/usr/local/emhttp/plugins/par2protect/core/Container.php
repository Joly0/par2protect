<?php
namespace Par2Protect\Core;

/**
 * A simple Dependency Injection Container.
 * Manages shared service instances.
 */
class Container {
    private $services = []; // Stores registration callbacks and shared flag
    private $sharedInstances = []; // Stores resolved shared instances (singleton cache)

    /**
     * Registers a service definition.
     *
     * @param string $key The service identifier.
     * @param callable $callable A callable that returns the service instance.
     * @param bool $shared Whether the service should be treated as a shared instance (singleton). Default true.
     */
    public function register(string $key, callable $callable, bool $shared = true): void {
        // Store the callable and the shared flag
        $this->services[$key] = ['callable' => $callable, 'shared' => $shared];
        // Ensure any previously resolved instance under this key is cleared if re-registering
        unset($this->sharedInstances[$key]);
    }

    /**
     * Resolves and returns a service instance.
     *
     * @param string $key The service identifier.
     * @return mixed The service instance.
     * @throws \Exception If the service is not found.
     */
    public function get(string $key) {
        if (!isset($this->services[$key])) {
            throw new \Exception("Service '{$key}' not found in container.");
        }

        $definition = $this->services[$key];

        // If it's a shared service and already instantiated, return the cached instance
        if ($definition['shared'] && isset($this->sharedInstances[$key])) {
            return $this->sharedInstances[$key];
        }

        // Otherwise, create the instance by calling the callable
        // Pass the container itself to the callable for dependency resolution
        $instance = call_user_func($definition['callable'], $this);

        // If it's a shared service, store the instance in the cache
        if ($definition['shared']) {
            $this->sharedInstances[$key] = $instance;
        }

        return $instance;
    }

    /**
     * Checks if a service is registered.
     *
     * @param string $key The service identifier.
     * @return bool
     */
    public function has(string $key): bool {
        return isset($this->services[$key]);
    }
}