<?php
namespace Par2Protect\Core\Commands;

use Par2Protect\Core\Config;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Traits\AddsPar2ResourceLimits;

/**
 * Builds the command string for 'par2 verify'.
 */
class Par2VerifyCommandBuilder {
    use AddsPar2ResourceLimits;

    private $config;
    private $logger;

    private $basePath;
    private $parityPath;
    private $quiet = true; // Default to quiet mode

    /**
     * Constructor.
     *
     * @param Config $config
     * @param Logger $logger
     */
    public function __construct(Config $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Sets the base path (-B option).
     *
     * @param string $path
     * @return $this
     */
    public function setBasePath(string $path): self {
        $this->basePath = $path;
        return $this;
    }

    /**
     * Sets the path to the PAR2 file(s) to verify.
     *
     * @param string $path
     * @return $this
     */
    public function setParityPath(string $path): self {
        $this->parityPath = $path;
        return $this;
    }

    /**
     * Sets whether to use quiet mode (-q option).
     *
     * @param bool $quiet
     * @return $this
     */
    public function setQuiet(bool $quiet): self {
        $this->quiet = $quiet;
        return $this;
    }

    /**
     * Builds the final command string.
     *
     * @return string
     * @throws \InvalidArgumentException If required parameters are missing.
     */
    public function buildCommandString(): string {
        if (empty($this->parityPath)) {
            throw new \InvalidArgumentException("Parity path must be set for verify command.");
        }
        if (empty($this->basePath)) {
            // Base path is technically optional for par2, but strongly recommended
            // and used consistently in the existing code. Let's enforce it.
             throw new \InvalidArgumentException("Base path must be set for verify command.");
        }

        // Use absolute path for par2 command
        // Use correct absolute path for par2 command
        $command = '/usr/local/bin/par2 verify';

        if ($this->quiet) {
            $command .= ' -q';
        }

        // Apply resource limits (CPU, Memory, I/O) using the trait
        $command = $this->applyResourceLimitParameters($command);

        // Add Base Path
        $command .= ' -B' . escapeshellarg($this->basePath);

        // Add Parity Path (target)
        $command .= ' ' . escapeshellarg($this->parityPath);

        return $command;
    }
}