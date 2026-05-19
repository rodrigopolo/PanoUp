<?php

/**
 * TilerBase
 *
 * Abstract base class for all panorama tile generators.
 *
 * Provides a unified image-driver layer that uses the PHP Imagick extension
 * when available and falls back transparently to GD. All concrete tiler classes
 * extend this class and call only the protected driver methods; they never
 * interact with Imagick or GD directly.
 *
 * Driver selection is performed once at construction time via extension_loaded().
 *
 * Protected driver API (for subclasses):
 *   loadImage(string $path): Imagick|GdImage
 *   imageWidth(Imagick|GdImage $img): int
 *   resizeImage(Imagick|GdImage $src, int $w, int $h): Imagick|GdImage
 *   cropAndSave(Imagick|GdImage $img, int $x, int $y, int $w, int $h, string $path): void
 *   destroyImage(Imagick|GdImage $img): void
 *   faceWidth(string $path): int          — reads only dimensions, no full load
 */
abstract class TilerBase
{
    protected const JPEG_QUALITY = 90;

    /** @var bool True when the Imagick extension is available */
    private bool $useImagick;

    public function __construct()
    {
        $this->useImagick = extension_loaded('imagick');
    }

    // =========================================================================
    // Driver: load
    // =========================================================================

    /**
     * Load a JPEG image from disk and return an opaque image handle.
     *
     * @return \Imagick|\GdImage
     * @throws \RuntimeException if the file cannot be loaded
     */
    protected function loadImage(string $path): mixed
    {
        if ($this->useImagick) {
            try {
                $img = new \Imagick($path);
                $img->setImageColorspace(\Imagick::COLORSPACE_SRGB);
                return $img;
            } catch (\ImagickException $e) {
                throw new \RuntimeException("Imagick failed to load '$path': " . $e->getMessage(), 0, $e);
            }
        }

        $img = imagecreatefromjpeg($path);
        if ($img === false) {
            throw new \RuntimeException("GD failed to load '$path'");
        }
        return $img;
    }

    // =========================================================================
    // Driver: dimensions
    // =========================================================================

    /**
     * Return the pixel width of a loaded image handle.
     *
     * @param \Imagick|\GdImage $img
     */
    protected function imageWidth(mixed $img): int
    {
        if ($this->useImagick) {
            /** @var \Imagick $img */
            return $img->getImageWidth();
        }
        /** @var \GdImage $img */
        return imagesx($img);
    }

    /**
     * Read only the dimensions of a JPEG file without loading pixel data.
     * Used by finalize() when processFace() may not have run yet.
     *
     * @throws \RuntimeException if the file is unreadable
     */
    protected function faceWidth(string $path): int
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new \RuntimeException("Cannot read image dimensions: $path");
        }
        return $info[0];
    }

    // =========================================================================
    // Driver: resize
    // =========================================================================

    /**
     * Return a new image that is a high-quality resize of $src to $w × $h.
     *
     * Imagick: Lanczos filter (FILTER_LANCZOS) — matches PIL Image.LANCZOS.
     * GD:      imagecopyresampled() — bicubic, the best GD offers.
     *
     * The original $src is NOT destroyed; caller is responsible.
     *
     * @param \Imagick|\GdImage $src
     * @return \Imagick|\GdImage  a fresh image handle
     */
    protected function resizeImage(mixed $src, int $w, int $h): mixed
    {
        if ($this->useImagick) {
            /** @var \Imagick $src */
            $clone = clone $src;
            $clone->resizeImage($w, $h, \Imagick::FILTER_LANCZOS, 1);
            return $clone;
        }

        /** @var \GdImage $src */
        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        return $dst;
    }

    // =========================================================================
    // Driver: crop and save
    // =========================================================================

    /**
     * Crop a region from $img and write it as a JPEG to $destPath.
     *
     * The region is ($x, $y) → ($x+$w, $y+$h).
     * $img is not modified or destroyed by this call.
     *
     * @param \Imagick|\GdImage $img
     * @throws \RuntimeException on write failure
     */
    protected function cropAndSave(mixed $img, int $x, int $y, int $w, int $h, string $destPath): void
    {
        if ($this->useImagick) {
            /** @var \Imagick $img */
            $tile = clone $img;
            $tile->cropImage($w, $h, $x, $y);
            $tile->setImagePage(0, 0, 0, 0);          // reset canvas after crop
            $tile->setImageFormat('jpeg');
            $tile->setImageCompressionQuality(self::JPEG_QUALITY);
            $tile->writeImage($destPath);
            $tile->destroy();
            return;
        }

        /** @var \GdImage $img */
        $tile = imagecreatetruecolor($w, $h);
        imagecopy($tile, $img, 0, 0, $x, $y, $w, $h);
        if (!imagejpeg($tile, $destPath, self::JPEG_QUALITY)) {
            imagedestroy($tile);
            throw new \RuntimeException("GD failed to write tile: $destPath");
        }
        imagedestroy($tile);
    }

    // =========================================================================
    // Driver: cleanup
    // =========================================================================

    /**
     * Release memory held by an image handle.
     *
     * @param \Imagick|\GdImage $img
     */
    protected function destroyImage(mixed $img): void
    {
        if ($this->useImagick) {
            /** @var \Imagick $img */
            $img->destroy();
            return;
        }
        /** @var \GdImage $img */
        imagedestroy($img);
    }

    // =========================================================================
    // Driver: crop-resize-save
    // =========================================================================

    /**
     * Crop a region from $img, resize it to $dstW × $dstH, and write as JPEG.
     *
     * Source region: ($x, $y) with dimensions $srcW × $srcH.
     * $img is not modified or destroyed.
     *
     * @param \Imagick|\GdImage $img
     */
    protected function cropResizeSave(
        mixed $img,
        int $x, int $y, int $srcW, int $srcH,
        int $dstW, int $dstH,
        string $destPath
    ): void {
        if ($this->useImagick) {
            /** @var \Imagick $img */
            $out = clone $img;
            $out->cropImage($srcW, $srcH, $x, $y);
            $out->setImagePage(0, 0, 0, 0);
            $out->resizeImage($dstW, $dstH, \Imagick::FILTER_LANCZOS, 1);
            $out->setImageFormat('jpeg');
            $out->setImageCompressionQuality(self::JPEG_QUALITY);
            $out->writeImage($destPath);
            $out->destroy();
            return;
        }

        /** @var \GdImage $img */
        $out = imagecreatetruecolor($dstW, $dstH);
        imagecopyresampled($out, $img, 0, 0, $x, $y, $dstW, $dstH, $srcW, $srcH);
        imagejpeg($out, $destPath, self::JPEG_QUALITY);
        imagedestroy($out);
    }

    // =========================================================================
    // Driver: canvas / composite / save
    // =========================================================================

    /**
     * Create a blank black canvas of $w × $h pixels.
     *
     * @return \Imagick|\GdImage
     */
    protected function createCanvas(int $w, int $h): mixed
    {
        if ($this->useImagick) {
            $canvas = new \Imagick();
            $canvas->newImage($w, $h, new \ImagickPixel('black'));
            $canvas->setImageFormat('jpeg');
            return $canvas;
        }

        $canvas = imagecreatetruecolor($w, $h);
        imagefilledrectangle($canvas, 0, 0, $w - 1, $h - 1, imagecolorallocate($canvas, 0, 0, 0));
        return $canvas;
    }

    /**
     * Resize $src to $w × $h and paste it onto $canvas at ($dstX, $dstY).
     *
     * $src is not destroyed; the resized copy is destroyed internally.
     *
     * @param \Imagick|\GdImage $canvas
     * @param \Imagick|\GdImage $src
     */
    protected function pasteResized(mixed $canvas, mixed $src, int $dstX, int $dstY, int $w, int $h): void
    {
        $resized = $this->resizeImage($src, $w, $h);

        if ($this->useImagick) {
            /** @var \Imagick $canvas */
            /** @var \Imagick $resized */
            $canvas->compositeImage($resized, \Imagick::COMPOSITE_OVER, $dstX, $dstY);
            $resized->destroy();
            return;
        }

        /** @var \GdImage $canvas */
        /** @var \GdImage $resized */
        imagecopy($canvas, $resized, $dstX, $dstY, 0, 0, $w, $h);
        imagedestroy($resized);
    }

    /**
     * Write a full image handle to disk as a JPEG.
     *
     * @param \Imagick|\GdImage $img
     * @throws \RuntimeException on write failure
     */
    protected function saveFullImage(mixed $img, string $destPath): void
    {
        if ($this->useImagick) {
            /** @var \Imagick $img */
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(self::JPEG_QUALITY);
            $img->writeImage($destPath);
            return;
        }

        /** @var \GdImage $img */
        if (!imagejpeg($img, $destPath, self::JPEG_QUALITY)) {
            throw new \RuntimeException("GD failed to write image: $destPath");
        }
    }

    // =========================================================================
    // Derived image generators (shared across tilers)
    // =========================================================================

    /**
     * Generate og_image.jpg (1200×630) from the front face.
     *
     * Crops the center strip of f.jpg to the 40:21 aspect ratio, then scales
     * to 1200×630. The front face is always square so only a vertical crop
     * is needed.
     */
    protected function generateOgImage(string $facesDir, string $outDir): void
    {
        $src   = $this->loadImage($this->facePath($facesDir, 'f'));
        $w     = $this->imageWidth($src);
        $cropH = (int)round($w * 21 / 40);
        $y     = (int)floor(($w - $cropH) / 2);
        $this->cropResizeSave($src, 0, $y, $w, $cropH, 1200, 630, $outDir . '/og_image.jpg');
        $this->destroyImage($src);
    }

    /**
     * Generate preview.jpg (256×1536) — vertical strip of all 6 cube faces.
     *
     * Face order top to bottom: l, f, r, b, u, d. Each face is 256×256 px.
     */
    protected function generatePreview(string $facesDir, string $outDir): void
    {
        $canvas = $this->createCanvas(256, 1536);
        foreach (['l', 'f', 'r', 'b', 'u', 'd'] as $i => $face) {
            $src = $this->loadImage($this->facePath($facesDir, $face));
            $this->pasteResized($canvas, $src, 0, $i * 256, 256, 256);
            $this->destroyImage($src);
        }
        $this->saveFullImage($canvas, $outDir . '/preview.jpg');
        $this->destroyImage($canvas);
    }

    // =========================================================================
    // Filesystem helper
    // =========================================================================

    /**
     * Create a directory (and all parents) if it does not already exist.
     *
     * @throws \RuntimeException on failure
     */
    protected function ensureDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0755, true)) {
            throw new \RuntimeException("Cannot create directory: $path");
        }
    }

    /**
     * Build and validate the path to a cube face JPEG.
     *
     * @throws \RuntimeException if the file does not exist
     */
    protected function facePath(string $facesDir, string $face): string
    {
        $path = rtrim($facesDir, '/\\') . DIRECTORY_SEPARATOR . $face . '.jpg';
        if (!file_exists($path)) {
            throw new \RuntimeException("Face image not found: $path");
        }
        return $path;
    }

    // =========================================================================
    // Abstract interface each tiler must implement
    // =========================================================================

    /**
     * Process one cube face: read it, generate all level tiles, write to disk.
     * Designed to be called once per face, concurrently across faces.
     *
     * Returns true on success, false if any error occurred during tile generation.
     */
    abstract public function processFace(string $facesDir, string $face, string $outDir): bool;

    /**
     * Called after all faces have been processed.
     * Returns the panorama metadata needed by the viewer.
     *
     * @return array{
     *     cubeResolution: int,
     *     maxLevel: int,
     *     tileResolution: int,
     *     panoTiles: mixed
     * }
     */
    abstract public function finalize(string $facesDir, string $outDir): array;
}
