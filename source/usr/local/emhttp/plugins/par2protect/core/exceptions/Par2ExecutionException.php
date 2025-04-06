<?php
namespace Par2Protect\Core\Exceptions;

/**
 * Exception thrown when a par2 command execution fails.
 */
class Par2ExecutionException extends \RuntimeException {
    protected $command;
    protected $returnCode;
    protected $output;

    /**
     * Constructor.
     *
     * @param string $message The exception message.
     * @param string $command The command that was executed.
     * @param int $returnCode The return code from the command.
     * @param string $output The output from the command.
     * @param int $code The Exception code.
     * @param \Throwable|null $previous The previous throwable used for the exception chaining.
     */
    public function __construct(
        string $message = "",
        string $command = "",
        int $returnCode = 0,
        string $output = "",
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->command = $command;
        $this->returnCode = $returnCode;
        $this->output = $output;
    }

    /**
     * Gets the command that failed.
     *
     * @return string
     */
    public function getCommand(): string {
        return $this->command;
    }

    /**
     * Gets the return code from the failed command.
     *
     * @return int
     */
    public function getReturnCode(): int {
        return $this->returnCode;
    }

    /**
     * Gets the output from the failed command.
     *
     * @return string
     */
    public function getOutput(): string {
        return $this->output;
    }
}