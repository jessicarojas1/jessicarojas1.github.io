<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Runs tests/Support/fake-clamd.php in a child process for the duration of a test class.
 */
final class FakeClamd
{
    /** @var resource|null */
    private $process;

    public readonly string $address;

    public function __construct(string $mode = 'normal')
    {
        $process = proc_open([\PHP_BINARY, __DIR__.'/fake-clamd.php', $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the fake clamd.');
        }
        $port = trim((string) fgets($pipes[1]));
        if (1 !== preg_match('/^\d+$/', $port)) {
            throw new \RuntimeException('Fake clamd did not report a port.');
        }
        $this->process = $process;
        $this->address = 'tcp://127.0.0.1:'.$port;
    }

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function __destruct()
    {
        $this->stop();
    }

    public static function eicar(): string
    {
        // Assembled at runtime so the repository never contains the literal test string.
        return 'X5O!P%@AP[4\PZX54(P^)7CC)7}$'.'EICAR-STANDARD-ANTIVIRUS-TEST-FILE'.'!$H+H*';
    }

    public static function minimalPdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    public static function minimalDocx(string $path): void
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>PAL</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
    }
}
