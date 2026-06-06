<?php

require_once __DIR__ . '/TilerBase.php';

/**
 * MarzipanoTiler
 *
 * Generates Marzipano-compatible multires cube tiles from pre-rendered cube faces.
 * Uses Imagick when available, falls back to GD transparently via TilerBase.
 *
 * Input faces expected in $facesDir:
 *   f.jpg  b.jpg  l.jpg  r.jpg  u.jpg  d.jpg
 *
 * Output structure:
 *   {outDir}/{levelIdx}/{face}_{row}_{col}.jpg
 *   Row and col are 1-based integers; all tiles are exactly 512×512.
 *
 * Level-size algorithm (mirrors marzipano.py):
 *   - Snap face width to nearest 512.
 *   - Select all candidates from [512, 1024, 2048, 4096, 8192, 16384] ≤ snapped.
 *   - Always include 512 as minimum.
 *   - Sort ascending.
 *
 * NOTE: The 256-px fallback/preview level is NOT a real tiled level.
 *       It appears only in panoTiles as the first entry with fallbackOnly:true.
 *
 * panoTiles:
 *   [ { tileSize: 256, size: 256, fallbackOnly: true },
 *     { tileSize: 512, size: 512 }, … ]
 */
class MarzipanoTiler extends TilerBase
{
	private const TILE_SIZE       = 512;
	private const PREVIEW_SIZE    = 256;
	private const CUBE_CANDIDATES = [16384, 8192, 4096, 2048, 1024, 512];

	/** @var int[] Ascending list of tiled level sizes (multiples of TILE_SIZE) */
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
	 * Mirrors marzipano.py compute_level_sizes():
	 *   1. Snap face width to nearest TILE_SIZE (512).
	 *   2. Include every candidate ≤ snapped.
	 *   3. Always include TILE_SIZE (512).
	 */
	private function computeLevelSizes(int $faceWidth): void
	{
		$snapped = self::roundToNearest($faceWidth, self::TILE_SIZE);
		$sizes   = array_filter(self::CUBE_CANDIDATES, fn($s) => $s <= $snapped);

		if (!in_array(self::TILE_SIZE, $sizes, true)) {
			$sizes[] = self::TILE_SIZE;
		}

		sort($sizes);
		$this->levelSizes = array_values($sizes);
	}

	// -------------------------------------------------------------------------
	// Public interface
	// -------------------------------------------------------------------------

	/**
	 * Process one cube face: generate all tiled-level tiles.
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
				$li = $idx + 1; // 1-based level index
				$this->writeTiles($src, $face, $li, $size, $outDir);
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
	 * @return array{
	 *     cubeResolution: int,
	 *     maxLevel: int,
	 *     tileResolution: int,
	 *     panoTiles: list<array{tileSize: int, size: int, fallbackOnly?: bool}>
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
		$this->generatePreview($facesDir, $outDir);

		// maxLevel counts only the real tiled levels; the 256-px preview is not a level.
		return [
			'cubeResolution' => $cubeResolution,
			'maxLevel'       => count($this->levelSizes),
			'tileResolution' => self::TILE_SIZE,
			'panoTiles'      => $this->buildPanoTiles(),
		];
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Resize $src to $levelSize and write JPEG tiles.
	 *
	 * Path: {outDir}/{li}/{face}_{row}_{col}.jpg  (1-based row/col)
	 * All Marzipano levels are exact multiples of TILE_SIZE → no edge tiles.
	 */
	private function writeTiles(
		mixed  $src,
		string $face,
		int    $li,
		int    $levelSize,
		string $outDir
	): void {
		$levelDir = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . $li;
		$this->ensureDir($levelDir);

		$resized = $this->resizeImage($src, $levelSize, $levelSize);

		// Levels are always exact multiples of TILE_SIZE
		$nTiles = intdiv($levelSize, self::TILE_SIZE);

		for ($row = 1; $row <= $nTiles; $row++) {
			$y0 = ($row - 1) * self::TILE_SIZE;

			for ($col = 1; $col <= $nTiles; $col++) {
				$x0   = ($col - 1) * self::TILE_SIZE;
				$dest = $levelDir . DIRECTORY_SEPARATOR . "{$face}_{$row}_{$col}.jpg";
				$this->cropAndSave($resized, $x0, $y0, self::TILE_SIZE, self::TILE_SIZE, $dest);
			}
		}

		$this->destroyImage($resized);
	}

	/**
	 * Build the Marzipano panoTiles descriptor array.
	 *
	 * First entry is the hardcoded 256-px fallbackOnly preview level.
	 * Subsequent entries are the real tiled levels, ascending.
	 */
	private function buildPanoTiles(): array
	{
		$tiles = [
			['tileSize' => self::PREVIEW_SIZE, 'size' => self::PREVIEW_SIZE, 'fallbackOnly' => true],
		];
		foreach ($this->levelSizes as $size) {
			$tiles[] = ['tileSize' => self::TILE_SIZE, 'size' => $size];
		}
		return $tiles;
	}
}
