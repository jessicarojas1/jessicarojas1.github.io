<?php

declare(strict_types=1);

namespace App\Storage;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * ClamAV daemon client using the INSTREAM protocol (file bytes are streamed; clamd needs
 * no access to PAL's filesystem). clamd's StreamMaxLength must be at least
 * PAL_MAX_UPLOAD_BYTES, or large files are reported as an error and rejected.
 *
 * With no PAL_CLAMD_ADDRESS, uploads are refused, unless PAL_ALLOW_UNSCANNED_UPLOADS=1
 * outside production (local development without ClamAV).
 */
#[AsAlias(MalwareScanner::class)]
final class ClamdScanner implements MalwareScanner
{
    private const CHUNK = 65_536;

    public function __construct(
        #[Autowire(env: 'PAL_CLAMD_ADDRESS')]
        private readonly string $address,
        #[Autowire(env: 'bool:PAL_ALLOW_UNSCANNED_UPLOADS')]
        private readonly bool $allowUnscanned,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        private readonly LoggerInterface $logger,
        private readonly float $timeoutSeconds = 30.0,
    ) {
    }

    public function scan(string $path): ScanResult
    {
        if ('' === trim($this->address)) {
            if ($this->allowUnscanned && 'prod' !== $this->environment) {
                $this->logger->warning('Upload accepted WITHOUT malware scanning (PAL_ALLOW_UNSCANNED_UPLOADS, non-production)');

                return ScanResult::clean('none (development)');
            }

            return ScanResult::error('clamd', 'scanner not configured');
        }

        $socket = @stream_socket_client($this->address, $errorCode, $errorMessage, $this->timeoutSeconds);
        if (false === $socket) {
            $this->logger->error('ClamAV is unreachable', ['address' => $this->address, 'error' => $errorMessage]);

            return ScanResult::error('clamd', 'scanner unreachable');
        }
        stream_set_timeout($socket, (int) ceil($this->timeoutSeconds));
        $file = @fopen($path, 'rb');
        if (false === $file) {
            fclose($socket);

            return ScanResult::error('clamd', 'file unreadable');
        }

        try {
            fwrite($socket, "zINSTREAM\0");
            while (!feof($file)) {
                $chunk = fread($file, self::CHUNK);
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                fwrite($socket, pack('N', \strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($file);
            fclose($socket);
        }

        if (1 === preg_match('/^stream: OK$/', $reply)) {
            return ScanResult::clean('clamd');
        }
        if (1 === preg_match('/^stream: (.+) FOUND$/', $reply, $m)) {
            return ScanResult::infected('clamd', mb_substr($m[1], 0, 200));
        }
        $this->logger->error('ClamAV returned an unexpected reply', ['reply' => mb_substr($reply, 0, 200)]);

        return ScanResult::error('clamd', 'unexpected scanner reply');
    }
}
