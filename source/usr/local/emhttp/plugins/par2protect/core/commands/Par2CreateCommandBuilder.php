<?php
namespace Par2Protect\Core\Commands;

use Par2Protect\Core\Config;
use Par2Protect\Core\Logger;
use Par2Protect\Core\Traits\AddsPar2ResourceLimits;
use Par2Protect\Core\Exceptions\Par2ExecutionException;

/**
 * Builds the command string for 'par2 create'.
 */
class Par2CreateCommandBuilder {
    use AddsPar2ResourceLimits;

    private $config;
    private $logger;

    // PAR2 create specific options
    private $redundancy = null;
    private $blockSize = null;
    private $blockCount = 0;
    private $recoveryFileCount = null;
    // Note: par2cmdline uses -s for block size OR recovery file size, not both.
    // We might need separate setters or logic to handle this ambiguity if both are needed.
    // For now, assuming block size takes precedence if both set.
    private $firstRecoveryNumber = null;
    private $uniformFileSize = false;
    private $memoryLimit = null; // Specific memory limit override for create?

    // Common options
    private $basePath;
    private $parityPath;
    private $sourceFiles = [];
    private $quiet = true;

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

    // --- Setters for PAR2 Create Options ---

    public function setRedundancy(int $percentage): self {
        $this->redundancy = $percentage;
        return $this;
    }

    public function setBlockSize(int $bytes): self {
        $this->blockSize = $bytes;
        return $this;
    }

    public function setBlockCount(int $count): self {
        $this->blockCount = $count;
        return $this;
    }

    /**
     * Validates that the block count doesn't exceed par2's limit.
     * Used before command execution to ensure valid parameters.
     *
     * @throws Par2ExecutionException If block count exceeds 32,768
     */
    private function validateBlockCount(): void {
        if ($this->blockCount > 32768) {
            throw new Par2ExecutionException(
                "The number of source files (" . $this->blockCount .
                ") exceeds the par2 format limit of 32,768."
            );
        }
    }

    public function setRecoveryFileCount(int $count): self {
        $this->recoveryFileCount = $count;
        return $this;
    }

    public function setFirstRecoveryNumber(int $number): self {
        $this->firstRecoveryNumber = $number;
        return $this;
    }

    public function setUniformFileSize(bool $uniform): self {
        $this->uniformFileSize = $uniform;
        return $this;
    }

    // --- Setters for Common Options ---

    public function setBasePath(string $path): self {
        $this->basePath = $path;
        return $this;
    }

    public function setParityPath(string $path): self {
        $this->parityPath = $path;
        return $this;
    }

    public function addSourceFile(string $path): self {
        $this->sourceFiles[] = $path;
        return $this;
    }

    public function addSourceFiles(array $paths): self {
        $this->sourceFiles = array_merge($this->sourceFiles, $paths);
        // Ensure uniqueness? Maybe not necessary if input is controlled.
        // $this->sourceFiles = array_unique(array_merge($this->sourceFiles, $paths));
        return $this;
    }

    public function setQuiet(bool $quiet): self {
        $this->quiet = $quiet;
        return $this;
    }

    public function resetSourceFiles(): self {
        $this->sourceFiles = [];
        return $this;
    }

    public function getParityPath(): ?string {
        return $this->parityPath;
    }

    public function getBasePath(): ?string {
        return $this->basePath;
    }

    public function getRedundancy(): ?int {
        return $this->redundancy;
    }

    public function getSourceFiles(): array {
        return $this->sourceFiles;
    }

    public function getBlockCount(): int {
        return $this->blockCount;
    }

    /**
     * Set the parity path and update internal state for argv construction.
     * This method sets or overrides the parity path and ensures it's included in argv.
     *
     * @param string $absolutePath The absolute path to the parity file
     * @return self
     */
    public function withParityPath(string $absolutePath): self {
        $this->parityPath = $absolutePath;
        return $this;
    }

    /**
     * Builds the command as an argument vector (argv) array for safe shell execution.
     * This eliminates the need for string-based command construction and prevents
     * argument quoting issues with paths containing spaces or special characters.
     *
     * @param bool $includeSourceFiles Whether to include source files in the argv array
     * @return array The command as an argv array
     * @throws \InvalidArgumentException If required parameters are missing
     */
    public function buildArgv(bool $includeSourceFiles = true): array {
        if (empty($this->parityPath)) {
            throw new \InvalidArgumentException("Parity path must be set for create command.");
        }

        if ($this->redundancy === null && $this->recoveryFileCount === null) {
            throw new \InvalidArgumentException("Either redundancy or recovery file count must be set for create command.");
        }

        // Validate block count doesn't exceed par2 limit
        if ($this->blockCount > 0) {
            $this->validateBlockCount();
        }

        // Start with the par2 executable path
        $argv = ['/usr/local/bin/par2', 'create'];

        // Add quiet flag if enabled
        if ($this->quiet) {
            $argv[] = '-q';
        }

        // Apply resource limits (CPU, Memory, I/O) using the trait for string-based approach
        // For argv approach, handle memory limit separately if set
        if (!empty($this->memoryLimit)) {
            $argv[] = '-m' . intval($this->memoryLimit);
        }

        // Add create-specific options
        if ($this->redundancy !== null) {
            $argv[] = '-r' . intval($this->redundancy);
        }
        if ($this->blockSize !== null) {
            $argv[] = '-s' . intval($this->blockSize);
        } elseif ($this->blockCount > 2000) {
            // Only add block count if > 2000 (consistent with buildCommandString)
            $argv[] = '-b' . intval($this->blockCount);
        }
        // Handle recovery file count - par2 uses -n
        if ($this->recoveryFileCount !== null) {
            $argv[] = '-n' . intval($this->recoveryFileCount);
        }
        if ($this->firstRecoveryNumber !== null) {
            $argv[] = '-f' . intval($this->firstRecoveryNumber);
        }
        if ($this->uniformFileSize) {
            $argv[] = '-u';
        }

        // Add base path if set
        if (!empty($this->basePath)) {
            $argv[] = '-B';
            $argv[] = $this->basePath;
        }

        // Add parity path (target archive name) - this is the final argument before source files
        $argv[] = '-a';
        $argv[] = $this->parityPath;

        // Add source files only if requested and not reading from stdin
        if ($includeSourceFiles) {
            // Add source files separator and files
            $argv[] = '--';
            foreach ($this->sourceFiles as $file) {
                $argv[] = $file;
            }
        }

        return $argv;
    }


    /**
     * Resets the builder state to defaults.
     *
     * @return self
     */
    public function reset(): self {
        // Reset PAR2 create specific options
        $this->redundancy = null;
        $this->blockSize = null;
        $this->blockCount = null;
        $this->recoveryFileCount = null;
        $this->firstRecoveryNumber = null;
        $this->uniformFileSize = false;
        $this->memoryLimit = null;

        // Reset Common options
        $this->basePath = null;
        $this->parityPath = null;
        $this->sourceFiles = [];
        $this->quiet = true; // Reset to default quiet state

        // Note: Does not reset config/logger references

        return $this;
    }

    // --- Build Method ---

    /**
     * Builds the final command string.
     *
     * @param bool $includeSourceFiles Whether to include source files in the command string and validation.
     * @return string
     * @throws \InvalidArgumentException If required parameters are missing.
     */
    public function buildCommandString(bool $includeSourceFiles = true): string {
        if (empty($this->parityPath)) {
            throw new \InvalidArgumentException("Parity path must be set for create command.");
        }
        // Only check for source files if they are meant to be included in this command string
        if ($includeSourceFiles && empty($this->sourceFiles) && $this->blockCount === 0) {
            throw new \InvalidArgumentException("At least one source file must be added for create command when includeSourceFiles is true.");
        }
        // Base path is optional for create if files have absolute paths, but required by our logic
         if (empty($this->basePath)) {
             throw new \InvalidArgumentException("Base path must be set for create command.");
         }
         if ($this->redundancy === null && $this->recoveryFileCount === null) {
             throw new \InvalidArgumentException("Either redundancy or recovery file count must be set for create command.");
         }
 
         // Validate block count doesn't exceed par2 limit
         if ($this->blockCount > 0) {
             $this->validateBlockCount();
         }
 
         // Use absolute path for par2 command
        // Use correct absolute path for par2 command
        $command = '/usr/local/bin/par2 create';

        if ($this->quiet) {
            $command .= ' -q';
        }

        // Apply resource limits (CPU, Memory, I/O) using the trait
        // Pass specific memory limit if provided for create? For now, uses global config.
        $command = $this->applyResourceLimitParameters($command);

        // Add create-specific options
        if ($this->redundancy !== null) {
            $command .= ' -r' . intval($this->redundancy);
        }
        if ($this->blockSize !== null) {
            $command .= ' -s' . intval($this->blockSize);
        } elseif ($this->blockCount > 2000) { // Only add block count if block size isn't set
             $command .= ' -b' . intval($this->blockCount);
        }
        // Handle recovery file count - par2 uses -n
        if ($this->recoveryFileCount !== null) {
             $command .= ' -n' . intval($this->recoveryFileCount);
        }
        if ($this->firstRecoveryNumber !== null) {
            $command .= ' -f' . intval($this->firstRecoveryNumber);
        }
        if ($this->uniformFileSize) {
            $command .= ' -u';
        }

        // Add Base Path
        $command .= ' -B' . escapeshellarg($this->basePath);

        // Add Parity Path (target archive name)
        $command .= ' -a' . escapeshellarg($this->parityPath);

        // Add Source Files only if requested and not reading from stdin
        if ($includeSourceFiles) {
            // IMPORTANT: Source files MUST come after the options and the target archive name (-a)
            // and after the '--' separator if used
            $command .= ' --'; // Use separator for clarity and safety
            foreach ($this->sourceFiles as $file) {
                $command .= ' ' . escapeshellarg($file);
            }
        }

        return $command;
    }
}