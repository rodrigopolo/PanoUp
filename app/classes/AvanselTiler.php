<?php

require_once __DIR__ . '/TilerBase.php';

/**
 * AvanselTiler
 *
 * Generates Avansel-compatible multires cube tiles from pre-rendered cube faces.
 * Uses Imagick when available, falls back to GD transparently via TilerBase.
 *
 * Input faces expected in $facesDir:
 *   f.jpg  b.jpg  l.jpg  r.jpg  u.jpg  d.jpg
 *
 * Output structure:
 *   {outDir}/1/{face}_{row}_{col}.jpg   ← fallback level (single tile per face)
 *   {outDir}/2/{face}_{row}_{col}.jpg   ← smallest tiled level
 *   …
 *   {outDir}/N/{face}_{row}_{col}.jpg   ← largest tiled level
 *
 * Naming: {face}_{row}_{col}.jpg  (0-indexed, no zero-padding)
 * JS callback: parseInt(l)+1  maps viewer level → directory number on disk.
 *
 * Level-size algorithm (mirrors avansel.py):
 *   - Tiled levels: repeatedly halve (ceiling) from face width until ≤ TILE_SIZE.
 *   - Fallback: smallest tiled level halved once more, capped at TILE_SIZE.
 *
 * panoTiles:
 *   [ { tileSize: fallbackPx, size: fallbackPx, fallback: true },
 *     { tileSize: 512, size: <level1> }, … ]
 */
class AvanselTiler extends TilerBase
{
	private const TILE_SIZE = 512;

	/** @var int Fallback level pixel size (auto-computed or overridden) */
	private int $fallbackSize = 0;

	/** @var int[] Ascending tiled level sizes */
	private array $tiledSizes = [];

	// -------------------------------------------------------------------------
	// Constructor — accepts optional fallback-size override
	// -------------------------------------------------------------------------

	public function __construct(private int $fallbackOverride = 0)
	{
		parent::__construct();
	}

	// -------------------------------------------------------------------------
	// Level-size computation
	// -------------------------------------------------------------------------

	/**
	 * Compute fallback and tiled sizes from the actual cube face pixel width.
	 *
	 * Tiled levels: halve via ceiling division from $faceWidth until ≤ TILE_SIZE,
	 * then sort ascending. Fallback = floor-of-half of the smallest tiled level,
	 * capped at TILE_SIZE (or $fallbackOverride if set).
	 */
	private function computeLevelSizes(int $faceWidth): void
	{
		$tiled = [];
		$s     = $faceWidth;
		while ($s > self::TILE_SIZE) {
			$tiled[] = $s;
			$s       = (int)(($s + 1) / 2);
		}
		sort($tiled); // ascending

		if ($this->fallbackOverride > 0) {
			$this->fallbackSize = $this->fallbackOverride;
		} else {
			$base               = !empty($tiled) ? $tiled[0] : $faceWidth;
			$this->fallbackSize = min((int)(($base + 1) / 2), self::TILE_SIZE);
		}

		$this->tiledSizes = $tiled;
	}

	// -------------------------------------------------------------------------
	// Public interface
	// -------------------------------------------------------------------------

	/**
	 * Process one cube face: generate fallback tile + all tiled-level tiles.
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

			// Compute levels once (idempotent across concurrent face calls)
			if (empty($this->tiledSizes) && $this->fallbackSize === 0) {
				$this->computeLevelSizes($this->imageWidth($src));
			}

			// Dir 1: fallback — one tile covers the whole face
			$this->writeTiles($src, $face, 1, $this->fallbackSize, $this->fallbackSize, $outDir);

			// Dirs 2…N: tiled levels, ascending
			foreach ($this->tiledSizes as $i => $size) {
				$this->writeTiles($src, $face, $i + 2, $size, self::TILE_SIZE, $outDir);
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
	 *     panoTiles: list<array{tileSize: int, size: int, fallback?: bool}>
	 * }
	 */
	public function finalize(string $facesDir, string $outDir): array
	{
		if (empty($this->tiledSizes) && $this->fallbackSize === 0) {
			$this->computeLevelSizes($this->faceWidth($this->facePath($facesDir, 'f')));
		}

		$cubeResolution = !empty($this->tiledSizes)
			? $this->tiledSizes[count($this->tiledSizes) - 1]
			: $this->fallbackSize;

		$this->generateOgImage($facesDir, $outDir);

		return [
			'cubeResolution' => $cubeResolution,
			'maxLevel'       => 1 + count($this->tiledSizes), // dir1 (fallback) + tiled dirs
			'tileResolution' => self::TILE_SIZE,
			'panoTiles'      => $this->buildPanoTiles(),
		];
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Resize $src to $levelSize × $levelSize and write JPEG tiles.
	 *
	 * Path pattern: {outDir}/{dirIdx}/{face}_{row}_{col}.jpg  (0-indexed, no padding)
	 *
	 * Edge tiles can be smaller than $tileSize when $levelSize % $tileSize ≠ 0
	 * (only applies to the Avansel fallback level and any non-512-aligned top levels).
	 */
	private function writeTiles(
		mixed  $src,
		string $face,
		int    $dirIdx,
		int    $levelSize,
		int    $tileSize,
		string $outDir
	): void {
		$levelDir = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . $dirIdx;
		$this->ensureDir($levelDir);

		$resized = $this->resizeImage($src, $levelSize, $levelSize);

		$nFull  = intdiv($levelSize, $tileSize);
		$nTotal = $nFull + ($levelSize % $tileSize !== 0 ? 1 : 0);

		for ($row = 0; $row < $nTotal; $row++) {
			$y0 = $row * $tileSize;
			$h  = min($tileSize, $levelSize - $y0);

			for ($col = 0; $col < $nTotal; $col++) {
				$x0   = $col * $tileSize;
				$w    = min($tileSize, $levelSize - $x0);
				$dest = $levelDir . DIRECTORY_SEPARATOR . "{$face}_{$row}_{$col}.jpg";
				$this->cropAndSave($resized, $x0, $y0, $w, $h, $dest);
			}
		}

		$this->destroyImage($resized);
	}

	/**
	 * Build the Avansel panoTiles descriptor array.
	 *
	 * Entry 0 has fallback:true; entries 1…N are regular tiled levels.
	 */
	private function buildPanoTiles(): array
	{
		$tiles = [
			['tileSize' => $this->fallbackSize, 'size' => $this->fallbackSize, 'fallback' => true],
		];
		foreach ($this->tiledSizes as $size) {
			$tiles[] = ['tileSize' => self::TILE_SIZE, 'size' => $size];
		}
		return $tiles;
	}
}
