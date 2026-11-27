<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Factory;

/**
 * A file reference to a placeholder image.
 */
final class ImageFactory extends FileReferenceFactory
{
    private int $width = 1600;

    private int $height = 900;

    /**
     * A grey PNG of the given size.
     */
    public static function placeholder(int $width = 1600, int $height = 900): static
    {
        $factory = self::new();
        $factory->width = $width;
        $factory->height = $height;

        return $factory;
    }

    #[\Override]
    protected function fileName(): string
    {
        return sprintf('placeholder-%dx%d.png', $this->width, $this->height);
    }

    #[\Override]
    protected function writeFileTo(string $path): void
    {
        $image = imagecreatetruecolor(max(1, $this->width), max(1, $this->height));
        if ($image === false) {
            throw new \RuntimeException('GD could not create the placeholder image.', 1763560502);
        }
        imagefill($image, 0, 0, (int)imagecolorallocate($image, 200, 205, 212));
        imagepng($image, $path);
    }
}
