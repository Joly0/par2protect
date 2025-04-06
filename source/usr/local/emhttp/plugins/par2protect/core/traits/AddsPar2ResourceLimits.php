<?php
namespace Par2Protect\Core\Traits;

/**
 * Trait providing functionality to add resource limit parameters to PAR2 commands.
 */
trait AddsPar2ResourceLimits {

    /**
     * Applies resource limit parameters (CPU, memory, I/O) to a command string.
     * Requires the class using this trait to have:
     * - A $config property (\Par2Protect\Core\Config instance).
     * - A $logger property (\Par2Protect\Core\Logger instance).
     *
     * @param string $baseCommand The base command string (e.g., "par2 verify -q").
     * @param array $options Optional overrides for config values (e.g., ['cpu_threads' => 4]).
     * @return string The command string with resource limit parameters added.
     */
    protected function applyResourceLimitParameters(string $baseCommand, array $options = []): string {
        // Ensure config and logger are available
        if (!property_exists($this, 'config') || !$this->config instanceof \Par2Protect\Core\Config) {
            // error_log("AddsPar2ResourceLimits trait requires a Config instance property named 'config'.");
            return $baseCommand; // Return unmodified command if config is missing
        }
         if (!property_exists($this, 'logger') || !$this->logger instanceof \Par2Protect\Core\Logger) {
            // error_log("AddsPar2ResourceLimits trait requires a Logger instance property named 'logger'.");
             // Continue without logging if logger is missing, but this shouldn't happen with DI
        }

        $commandWithLimits = $baseCommand;
        $ionicePrefix = '';

        // CPU threads - only add if we have a non-empty value
        $threads = $options['cpu_threads'] ?? $this->config->get('resource_limits.max_cpu_usage');
        if (!empty($threads) && is_numeric($threads) && $threads > 0) {
            $commandWithLimits .= " -t" . intval($threads);
        }

        // Memory usage - only add if we have a non-empty value
        $memory = $options['memory'] ?? $this->config->get('resource_limits.max_memory_usage');
        if (!empty($memory) && is_numeric($memory) && $memory > 0) {
            $commandWithLimits .= " -m" . intval($memory);
        }

        // Parallel file hashing
        $parallelHashing = $options['parallel_hashing'] ?? $this->config->get('resource_limits.parallel_file_hashing');
        // Check for boolean true or a positive integer value
         if ($parallelHashing === true || (is_numeric($parallelHashing) && intval($parallelHashing) > 0)) {
             // If it's just 'true', par2 uses default parallelism, otherwise specify the number
             $paramValue = ($parallelHashing === true) ? '' : intval($parallelHashing);
             $commandWithLimits .= " -T" . $paramValue;
         }


        // I/O priority if set
        $ioPriority = $options['io_priority'] ?? $this->config->get('resource_limits.io_priority');
        if ($ioPriority && $ioPriority !== 'none') {
            // Map priority levels to ionice classes
            $ioniceClass = 2; // Default to best-effort class
            $ioniceLevel = 4; // Default to normal priority (range 0-7)

            if ($ioPriority === 'high') {
                $ioniceLevel = 0; // Highest priority in best-effort class
            } elseif ($ioPriority === 'normal') {
                $ioniceLevel = 4; // Normal priority
            } elseif ($ioPriority === 'low') {
                $ioniceLevel = 7; // Lowest priority
            } else {
                 if (property_exists($this, 'logger')) {
                    $this->logger->warning("Invalid I/O priority setting found, using default.", ['priority' => $ioPriority]);
                 }
            }

            // Assume ionice exists at /usr/bin/ionice and prepend it.
            // The exec call will fail later if it's not actually available.
            // Removed the check using 'command -v' as 'command' itself wasn't found.
            $ionicePrefix = "/usr/bin/ionice -c $ioniceClass -n $ioniceLevel ";
            if (property_exists($this, 'logger')) {
                 // Log that we are attempting to use ionice
                 $this->logger->debug("Prepending ionice command", ['prefix' => $ionicePrefix]);
            }
        }

        // Return the command, prepended with ionice if applicable
        return $ionicePrefix . $commandWithLimits;
    }
}