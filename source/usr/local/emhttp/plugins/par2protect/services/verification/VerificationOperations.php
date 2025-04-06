<?php
namespace Par2Protect\Services\Verification;

use Par2Protect\Core\Logger;
use Par2Protect\Core\Config;
use Par2Protect\Core\Exceptions\Par2ExecutionException;
use Par2Protect\Core\Commands\Par2VerifyCommandBuilder;
use Par2Protect\Core\Commands\Par2RepairCommandBuilder;

/**
 * Handles verification and repair operations using par2 commands
 */
class VerificationOperations {
    private $logger;
    private $config;
    private $verifyBuilder;
    private $repairBuilder;

    /**
     * VerificationOperations constructor
     */
    public function __construct(
        Logger $logger,
        Config $config,
        Par2VerifyCommandBuilder $verifyBuilder,
        Par2RepairCommandBuilder $repairBuilder
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->verifyBuilder = $verifyBuilder;
        $this->repairBuilder = $repairBuilder;
    }

    /**
     * Verify par2 files
     *
     * @param string $path Path to verify
     * @param string $par2Path Path to par2 file
     * @param string $mode Verification mode (file or directory)
     * @return array ['status' => string, 'details' => string] The verification status and details.
     * @throws Par2ExecutionException If the par2 command execution fails unexpectedly.
     * @throws \Exception For other errors like missing par2 files in individual mode.
     */
    public function verifyPar2Files($path, $par2Path, $mode): array // Changed return type hint
    {
        $isIndividualFiles = strpos($mode, 'Individual Files') === 0;
        $basePath = ($mode === 'directory' || $isIndividualFiles) ? $path : dirname($path);

        // Standard verification for regular files and directories
        if (!$isIndividualFiles || !is_dir($par2Path)) {
            $this->logger->debug("DIAGNOSTIC: Par2 command construction for standard mode", [ /* context */ ]);

            $command = $this->verifyBuilder
                ->setBasePath($basePath)
                ->setParityPath($par2Path)
                ->setQuiet(true)
                ->buildCommandString();

            $this->logger->debug("Executing par2 command", ['command' => $command]);
            exec($command . ' 2>&1', $output, $returnCode);

            $outputStr = implode("\n", $output);
            $status = 'UNKNOWN';
            $details = $outputStr; // Store output as details

            if ($returnCode === 0) {
                $status = 'VERIFIED';
            } elseif (strpos($outputStr, 'damaged') !== false) {
                $status = 'DAMAGED';
            } elseif (strpos($outputStr, 'missing') !== false) {
                $status = 'MISSING';
            } else {
                 $this->logger->error("Par2 verify command failed with unexpected output", [ /* context */ ]);
                throw new Par2ExecutionException("Par2 verify command failed unexpectedly", $command, $returnCode, $outputStr);
            }
            // Return array for standard mode
            return ['status' => $status, 'details' => $details];

        } else { // For Individual Files mode ($isIndividualFiles && is_dir($par2Path))
            $this->logger->debug("DIAGNOSTIC: Individual Files mode detected, par2_path is a directory", [ /* context */ ]);

            $par2Files = glob($par2Path . '/*.par2');
            $mainPar2Files = [];
            foreach ($par2Files as $file) {
                if (!preg_match('/\.vol\d+\+\d+\.par2$/', $file)) { $mainPar2Files[] = $file; }
            }
            $this->logger->debug("DIAGNOSTIC: Found main par2 files in directory", [ /* context */ ]);

            if (count($mainPar2Files) === 0) {
                // Return error status if no files found, instead of throwing exception directly
                return ['status' => 'ERROR', 'details' => "No main .par2 files found in directory: $par2Path"];
            }

            $overallStatus = 'VERIFIED';
            $allDetails = [];
            $verifiedCount = 0; $damagedCount = 0; $missingCount = 0; $errorCount = 0;

            foreach ($mainPar2Files as $par2File) {
                $command = $this->verifyBuilder
                    ->setBasePath($basePath)->setParityPath($par2File)->setQuiet(true)->buildCommandString();
                $this->logger->debug("DIAGNOSTIC: Verifying individual file", [ /* context */ ]);

                try {
                    exec($command . ' 2>&1', $output, $returnCode);
                    $outputStr = implode("\n", $output);
                    $fileStatus = 'UNKNOWN';

                    if ($returnCode === 0) { $fileStatus = 'VERIFIED'; $verifiedCount++; }
                    elseif (strpos($outputStr, 'damaged') !== false) { $fileStatus = 'DAMAGED'; $damagedCount++; $overallStatus = 'DAMAGED'; }
                    elseif (strpos($outputStr, 'missing') !== false) { $fileStatus = 'MISSING'; $missingCount++; if ($overallStatus !== 'DAMAGED') $overallStatus = 'MISSING'; }
                    else { $this->logger->error("Par2 verify command failed for individual file", [ /* context */ ]); $fileStatus = 'ERROR'; $errorCount++; if ($overallStatus !== 'DAMAGED' && $overallStatus !== 'MISSING') $overallStatus = 'ERROR'; }
                } catch (\Exception $e) {
                     $this->logger->error("Exception during individual par2 verify execution", [ /* context */ ]);
                     $fileStatus = 'ERROR'; $errorCount++; $overallStatus = 'ERROR';
                     $outputStr = "Exception: " . $e->getMessage(); // Add exception to details
                }

                $originalFilename = basename($par2File);
                if (substr($originalFilename, -5) === '.par2') { $originalFilename = substr($originalFilename, 0, -5); }
                $this->logger->debug("DIAGNOSTIC: Filename transformation", [ /* context */ ]);
                $allDetails[] = $originalFilename . ": " . $fileStatus . ($fileStatus !== 'VERIFIED' ? "\nOutput:\n" . $outputStr : ''); // Include output in details for non-verified
                $this->logger->debug("DIAGNOSTIC: Individual file verification result", [ /* context */ ]);
            }

            $detailsSummary = "Verified: $verifiedCount, Damaged: $damagedCount, Missing: $missingCount, Error: $errorCount\n---\n";
            $detailsSummary .= implode("\n---\n", $allDetails);
            $this->logger->debug("DIAGNOSTIC: Overall verification result for Individual Files", [ /* context */ ]);

            // If errors occurred, ensure overall status reflects it
            if ($errorCount > 0) $overallStatus = 'ERROR';

            // Return array for individual files mode
            return ['status' => $overallStatus, 'details' => $detailsSummary];
        }
    }

    /**
     * Repair par2 files
     *
     * @param string $path Path to repair
     * @param string $par2Path Path to par2 file
     * @param string $mode Repair mode (file or directory)
     * @return array ['status' => string, 'details' => string] The repair status and details.
     * @throws Par2ExecutionException If the par2 command execution fails unexpectedly.
     * @throws \Exception For other errors like missing par2 files in individual mode.
     */
    public function repairPar2Files($path, $par2Path, $mode): array // Changed return type hint
    {
        $isIndividualFiles = strpos($mode, 'Individual Files') === 0;
        $basePath = ($mode === 'directory' || $isIndividualFiles) ? $path : dirname($path);

        // Standard repair for regular files and directories
        if (!$isIndividualFiles || !is_dir($par2Path)) {
            $this->logger->debug("DIAGNOSTIC: Par2 repair command construction for standard mode", [ /* context */ ]);

            $command = $this->repairBuilder
                ->setBasePath($basePath)
                ->setParityPath($par2Path)
                ->setQuiet(true)
                ->buildCommandString();

            $this->logger->debug("Executing par2 command", ['command' => $command]);
            exec($command . ' 2>&1', $output, $returnCode);

            $outputStr = implode("\n", $output);
            $status = 'UNKNOWN';
            $details = $outputStr; // Store output as details

            if ($returnCode === 0 || strpos($outputStr, 'repair complete') !== false || strpos($outputStr, 'Repair is not required') !== false) {
                 $status = 'REPAIRED'; // Treat "not required" as successfully repaired/verified state
            } elseif (strpos($outputStr, 'repair is not possible') !== false || strpos($outputStr, 'repair not possible') !== false) {
                if (strpos($outputStr, 'too many') !== false || strpos($outputStr, 'not enough recovery blocks') !== false) {
                    $status = 'MISSING';
                } else {
                    $status = 'REPAIR_FAILED';
                }
            } else {
                 $this->logger->error("Par2 repair command failed with unexpected output", [ /* context */ ]);
                throw new Par2ExecutionException("Par2 repair command failed unexpectedly", $command, $returnCode, $outputStr);
            }
            // Return array for standard mode
            return ['status' => $status, 'details' => $details];

        } else { // For Individual Files mode ($isIndividualFiles && is_dir($par2Path))
            $this->logger->debug("DIAGNOSTIC: Individual Files mode detected, par2_path is a directory", [ /* context */ ]);

            $par2Files = glob($par2Path . '/*.par2');
            $mainPar2Files = [];
            foreach ($par2Files as $file) {
                if (!preg_match('/\.vol\d+\+\d+\.par2$/', $file)) { $mainPar2Files[] = $file; }
            }
            $this->logger->debug("DIAGNOSTIC: Found main par2 files in directory", [ /* context */ ]);

            if (count($mainPar2Files) === 0) {
                // Return error status if no files found
                return ['status' => 'ERROR', 'details' => "No main .par2 files found in directory: $par2Path"];
            }

            $overallStatus = 'REPAIRED';
            $allDetails = [];
            $repairedCount = 0; $missingCount = 0; $failedCount = 0; $errorCount = 0;

            foreach ($mainPar2Files as $par2File) {
                $command = $this->repairBuilder
                    ->setBasePath($basePath)->setParityPath($par2File)->setQuiet(true)->buildCommandString();
                $this->logger->debug("DIAGNOSTIC: Repairing individual file", [ /* context */ ]);

                try {
                    exec($command . ' 2>&1', $output, $returnCode);
                    $outputStr = implode("\n", $output);
                    $fileStatus = 'UNKNOWN';

                    if ($returnCode === 0 || strpos($outputStr, 'repair complete') !== false || strpos($outputStr, 'Repair is not required') !== false) {
                        $fileStatus = 'REPAIRED'; $repairedCount++;
                    } elseif (strpos($outputStr, 'repair is not possible') !== false || strpos($outputStr, 'repair not possible') !== false) {
                        if (strpos($outputStr, 'too many') !== false || strpos($outputStr, 'not enough recovery blocks') !== false) {
                            $fileStatus = 'MISSING'; $missingCount++; if ($overallStatus === 'REPAIRED') $overallStatus = 'MISSING';
                        } else {
                            $fileStatus = 'REPAIR_FAILED'; $failedCount++; if ($overallStatus === 'REPAIRED' || $overallStatus === 'MISSING') $overallStatus = 'REPAIR_FAILED';
                        }
                    } else {
                        $this->logger->error("Par2 repair command failed for individual file", [ /* context */ ]);
                        $fileStatus = 'ERROR'; $errorCount++; $overallStatus = 'ERROR';
                    }
                 } catch (\Exception $e) {
                     $this->logger->error("Exception during individual par2 repair execution", [ /* context */ ]);
                     $fileStatus = 'ERROR'; $errorCount++; $overallStatus = 'ERROR';
                     $outputStr = "Exception: " . $e->getMessage();
                 }

                $originalFilename = basename($par2File);
                if (substr($originalFilename, -5) === '.par2') { $originalFilename = substr($originalFilename, 0, -5); }
                $this->logger->debug("DIAGNOSTIC: Filename transformation", [ /* context */ ]);
                $allDetails[] = $originalFilename . ": " . $fileStatus . ($fileStatus !== 'REPAIRED' ? "\nOutput:\n" . $outputStr : ''); // Include output for non-repaired
                $this->logger->debug("DIAGNOSTIC: Individual file repair result", [ /* context */ ]);
            }

            $detailsSummary = "Repaired: $repairedCount, Missing: $missingCount, Failed: $failedCount, Error: $errorCount\n---\n";
            $detailsSummary .= implode("\n---\n", $allDetails);
            $this->logger->debug("DIAGNOSTIC: Overall repair result for Individual Files", [ /* context */ ]);

            if ($errorCount > 0) $overallStatus = 'ERROR'; // Ensure overall status reflects errors

            // Return array for individual files mode
            return ['status' => $overallStatus, 'details' => $detailsSummary];
        }
    }

} // End of class VerificationOperations