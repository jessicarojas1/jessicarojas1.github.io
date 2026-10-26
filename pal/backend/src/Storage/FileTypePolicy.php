<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Decides what a file IS from its bytes (libmagic), never from its name, and allows only
 * document formats PAL serves. Macro-enabled Office formats and legacy binary Office
 * formats (which can carry macros undetectably) are not allowed. The file's extension
 * must agree with the detected type, so a download is never mislabelled.
 */
final class FileTypePolicy
{
    /** detected MIME => allowed extensions */
    public const ALLOWED = [
        'application/pdf' => ['pdf'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'text/plain' => ['txt'],
    ];

    public function detect(string $path): string
    {
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return \is_string($mime) ? $mime : 'application/octet-stream';
    }

    /**
     * @return string|null a user-facing reason when not allowed, null when allowed
     */
    public function rejectionReason(string $detectedMime, string $filename): ?string
    {
        if (!isset(self::ALLOWED[$detectedMime])) {
            return 'This file type is not accepted. Upload PDF, Word (.docx), Excel (.xlsx), PowerPoint (.pptx) or plain text.';
        }
        $extension = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        if (!\in_array($extension, self::ALLOWED[$detectedMime], true)) {
            return \sprintf('The file content is %s, which does not match its .%s extension.', self::ALLOWED[$detectedMime][0], '' === $extension ? '(none)' : $extension);
        }

        return null;
    }

    public static function safeFilename(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F"\/\\\\<>:|?*]+/u', '_', $base), " .");

        return '' === $clean ? 'file' : mb_substr($clean, 0, 200);
    }
}
