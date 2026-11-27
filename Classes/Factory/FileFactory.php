<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Factory;

/**
 * A file reference to a copy of a file of the project, for anything a placeholder image cannot stand for:
 * a PDF, a video, an image with real content.
 */
final class FileFactory extends FileReferenceFactory
{
    private string $source = '';

    private ?string $name = null;

    /**
     * @param string $path absolute path of the file; it is copied, never moved
     * @param string|null $name name of the file in the storage (no path), the name of the source by default
     */
    public static function fromPath(string $path, ?string $name = null): static
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException(sprintf('File "%s" does not exist.', $path), 1763990101);
        }
        if ($name !== null) {
            self::assertIsFileName($name);
        }
        $factory = self::new();
        $factory->source = $path;
        $factory->name = $name;

        return $factory;
    }

    #[\Override]
    protected function fileName(): string
    {
        return $this->name ?? basename($this->source);
    }

    #[\Override]
    protected function writeFileTo(string $path): void
    {
        if ($this->source === '' || !copy($this->source, $path)) {
            throw new \RuntimeException(sprintf('File "%s" could not be copied.', $this->source), 1763990102);
        }
    }
}
