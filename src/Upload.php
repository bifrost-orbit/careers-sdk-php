<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers;

/**
 * A file to send: a CV, a file question's answer or a document in the candidate portal. PDF or Word, up to 10 MB.
 */
final class Upload
{
    public function __construct(
        public readonly string $name,
        public readonly string $contents,
        public readonly string $type = 'application/pdf',
    ) {
    }

    public static function fromPath(string $path, ?string $name = null, ?string $type = null): self
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException("The file {$path} could not be read");
        }
        $name ??= basename($path);
        $type ??= str_ends_with(strtolower($name), '.docx')
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';
        return new self($name, $contents, $type);
    }
}
