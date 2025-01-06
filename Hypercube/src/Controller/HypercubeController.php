<?php

namespace App\Islandora\Hypercube\Controller;

use GuzzleHttp\Psr7\StreamWrapper;
use Islandora\Crayfish\Commons\CmdExecuteService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;

class HypercubeController
{
    protected CmdExecuteService $cmd;
    protected string $tesseract_executable;
    protected string $pdftotext_executable;
    protected LoggerInterface $log;

    // Whitelist of allowed OCR arguments
    private const ALLOWED_TESSERACT_ARGS = [
        'lang',          // Language
        'psm',          // Page segmentation mode
        'oem',          // OCR Engine mode
        'dpi'           // Resolution
    ];

    private const ALLOWED_PDFTOTEXT_ARGS = [
        'f',            // First page
        'l',            // Last page
        'layout',       // Maintain original layout
        'raw'          // Raw output
    ];

    public function __construct(
        CmdExecuteService $cmd,
        string $tesseract_executable,
        string $pdftotext_executable,
        LoggerInterface $log
    ) {
        $this->cmd = $cmd;
        $this->tesseract_executable = $tesseract_executable;
        $this->pdftotext_executable = $pdftotext_executable;
        $this->log = $log;
    }

    /**
     * Sanitize and validate command arguments
     * @param string|null $argsString
     * @param array $allowedArgs
     * @return string
     */
    private function sanitizeArgs(?string $argsString, array $allowedArgs): string 
    {
        if (empty($argsString)) {
            return '';
        }

        $sanitizedArgs = [];
        $args = explode(' ', $argsString);

        foreach ($args as $arg) {
            // Remove any potential command injection characters
            $arg = preg_replace('/[;&|`$]/', '', $arg);
            
            // Parse argument name and value
            if (preg_match('/^--?([a-zA-Z0-9_-]+)(?:=(.*))?$/', $arg, $matches)) {
                $argName = strtolower($matches[1]);
                $argValue = $matches[2] ?? null;

                // Validate against whitelist
                if (in_array($argName, $allowedArgs, true)) {
                    if ($argValue !== null) {
                        // Escape the value
                        $argValue = escapeshellarg($argValue);
                        $sanitizedArgs[] = "--{$argName}={$argValue}";
                    } else {
                        $sanitizedArgs[] = "--{$argName}";
                    }
                } else {
                    $this->log->warning("Rejected unauthorized argument", ['arg' => $argName]);
                }
            }
        }

        return implode(' ', $sanitizedArgs);
    }

    public function ocr(Request $request): Response
    {
        try {
            $fedora_resource = $request->attributes->get('fedora_resource');
            if (!$fedora_resource) {
                throw new \RuntimeException('No Fedora resource provided');
            }

            $body = StreamWrapper::getResource($fedora_resource->getBody());
            $args = $request->headers->get('X-Islandora-Args');
            $content_type = $fedora_resource->getHeader('Content-Type')[0] ?? null;

            if (!$content_type) {
                throw new \RuntimeException('Content-Type header is required');
            }

            $this->log->debug("Got Content-Type:", ['type' => $content_type]);

            // Sanitize arguments based on content type
            if ($content_type === 'application/pdf') {
                $sanitized_args = $this->sanitizeArgs($args, self::ALLOWED_PDFTOTEXT_ARGS);
                $executable = escapeshellcmd($this->pdftotext_executable);
                $cmd_array = [$executable];
                if (!empty($sanitized_args)) {
                    $cmd_array[] = $sanitized_args;
                }
                $cmd_array[] = '- -'; // Input/output as stdin/stdout
                $cmd_string = implode(' ', $cmd_array);
            } else {
                $sanitized_args = $this->sanitizeArgs($args, self::ALLOWED_TESSERACT_ARGS);
                $executable = escapeshellcmd($this->tesseract_executable);
                $cmd_array = [$executable, 'stdin', 'stdout'];
                if (!empty($sanitized_args)) {
                    $cmd_array[] = $sanitized_args;
                }
                $cmd_string = implode(' ', $cmd_array);
            }

            $this->log->debug("Executing command:", ['cmd' => $cmd_string]);

            return new StreamedResponse(
                $this->cmd->execute($cmd_string, $body),
                200,
                ['Content-Type' => $request->headers->get('Accept') ?? 'text/plain']
            );

        } catch (ProcessFailedException $e) {
            $this->log->error("Process execution failed", ['error' => $e->getMessage()]);
            return new Response('Process execution failed', 500);
        } catch (\RuntimeException $e) {
            $this->log->error("Runtime error", ['error' => $e->getMessage()]);
            return new Response($e->getMessage(), 500);
        } catch (\Exception $e) {
            $this->log->error("Unexpected error", ['error' => $e->getMessage()]);
            return new Response('An unexpected error occurred', 500);
        }
    }

    public function options(): BinaryFileResponse
    {
        return new BinaryFileResponse(
            __DIR__ . "/../../public/static/convert.ttl",
            200,
            ['Content-Type' => 'text/turtle']
        );
    }
}
