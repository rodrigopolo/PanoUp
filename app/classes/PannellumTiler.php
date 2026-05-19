<?php

require_once __DIR__ . '/TilerBase.php';

/**
 * PannellumTiler
 *
 * Generates Pannellum-compatible multires cube tiles from pre-rendered cube faces.
 * Uses Imagick when available, falls back to GD transparently via TilerBase.
 *
 * Input faces expected in $facesDir:
 *   f.jpg  b.jpg  l.jpg  r.jpg  u.jpg  d.jpg
 *
 * Output structure:
 *   {outDir}/{levelIdx}/{face}_{row}_{col}.jpg
 *   Row and col are 0-indexed; all tiles are exactly 512×512.
 *
 * Level-size algorithm (mirrors pannellum.py):
 *   - Snap face width to nearest 512.
 *   - Select all candidates from [512, 1024, 2048, 4096, 8192, 16384] ≤ snapped.
 *   - Sort ascending (level 1 = smallest).
 *
 * Pannellum uses scalar config values, not a structured tiles descriptor.
 * panoTiles is therefore null.
 */
class PannellumTiler extends TilerBase
{
    private const TILE_SIZE   = 512;
    private const CUBE_LEVELS = [16384, 8192, 4096, 2048, 1024, 512];

    /** @var int[] Ascending list of tiled level sizes */
    private array $levelSizes = [];

    // -------------------------------------------------------------------------
    // Level-size computation
    // -------------------------------------------------------------------------

    /**
     * Round $number to the nearest multiple of $divisor.
     */
    private static function roundToNearest(int $number, int $divisor): int
    {
        return (int)(((int)($number + $divisor / 2) / $divisor)) * $divisor;
    }

    /**
     * Compute ascending level sizes from the cube face pixel width.
     *
     * Mirrors pannellum.py compute_level_sizes():
     *   1. Snap face width to nearest TILE_SIZE (512).
     *   2. Include every CUBE_LEVELS entry ≤ snapped.
     *   3. Sort ascending (level 1 = smallest).
     */
    private function computeLevelSizes(int $faceWidth): void
    {
        $snapped = self::roundToNearest($faceWidth, self::TILE_SIZE);
        $sizes   = array_filter(self::CUBE_LEVELS, fn($s) => $snapped >= $s);

        sort($sizes);
        $this->levelSizes = array_values($sizes);
    }

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Process one cube face: generate all level tiles.
     *
     * @param string $facesDir  Directory containing {face}.jpg files
     * @param string $face      One of: f, b, l, r, u, d
     * @param string $outDir    Root output directory
     *
     * @return bool  true on success, false if any error occurred
     */
    public function processFace(string $facesDir, string $face, string $outDir): bool
    {
        try {
            $src = $this->loadImage($this->facePath($facesDir, $face));

            if (empty($this->levelSizes)) {
                $this->computeLevelSizes($this->imageWidth($src));
            }

            foreach ($this->levelSizes as $idx => $size) {
                $li       = $idx + 1; // 1-based level index → directory name
                $levelDir = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . $li;
                $this->writeTiles($src, $face, $size, $levelDir);
            }

            $this->destroyImage($src);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Finalize after all faces are processed; return panorama metadata.
     *
     * panoTiles is null — Pannellum consumes cubeResolution, maxLevel, and
     * tileResolution as scalar values directly in its multiRes config block.
     *
     * @return array{
     *     cubeResolution: int,
     *     maxLevel: int,
     *     tileResolution: int,
     *     panoTiles: null
     * }
     */
    public function finalize(string $facesDir, string $outDir): array
    {
        if (empty($this->levelSizes)) {
            $this->computeLevelSizes($this->faceWidth($this->facePath($facesDir, 'f')));
        }

        $cubeResolution = !empty($this->levelSizes)
            ? $this->levelSizes[count($this->levelSizes) - 1]
            : 0;

        $this->generateOgImage($facesDir, $outDir);

        return [
            'cubeResolution' => $cubeResolution,
            'maxLevel'       => count($this->levelSizes),
            'tileResolution' => self::TILE_SIZE,
            'panoTiles'      => null,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Resize $src to $levelSize and write JPEG tiles into $levelDir.
     *
     * Path: {levelDir}/{face}_{row}_{col}.jpg  (0-indexed row/col, no padding)
     * All Pannellum levels are exact multiples of TILE_SIZE → no edge tiles.
     */
    private function writeTiles(
        mixed  $src,
        string $face,
        int    $levelSize,
        string $levelDir
    ): void {
        $this->ensureDir($levelDir);

        $resized = $this->resizeImage($src, $levelSize, $levelSize);

        // All levels are exact multiples of TILE_SIZE
        $nTiles = intdiv($levelSize, self::TILE_SIZE);

        for ($row = 0; $row < $nTiles; $row++) {
            $y0 = $row * self::TILE_SIZE;

            for ($col = 0; $col < $nTiles; $col++) {
                $x0   = $col * self::TILE_SIZE;
                $dest = $levelDir . DIRECTORY_SEPARATOR . "{$face}_{$row}_{$col}.jpg";
                $this->cropAndSave($resized, $x0, $y0, self::TILE_SIZE, self::TILE_SIZE, $dest);
            }
        }

        $this->destroyImage($resized);
    }
}
