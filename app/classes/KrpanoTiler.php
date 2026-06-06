<?php

require_once __DIR__ . '/TilerBase.php';

/**
 * KrpanoTiler
 *
 * Generates krpano-compatible multires cube tiles from pre-rendered cube faces.
 * Uses Imagick when available, falls back to GD transparently via TilerBase.
 *
 * Input faces expected in $facesDir:
 *   f.jpg  b.jpg  l.jpg  r.jpg  u.jpg  d.jpg
 *
 * Output structure:
 *   {outDir}/{face}/l{li}/{row:02d}/l{li}_{face}_{row:02d}_{col:02d}.jpg
 *   Rows and cols are 1-based with 2-digit zero-padding.
 *
 * Level-size algorithm (mirrors krpano.py):
 *   max = round(faceWidth / 128) × 128
 *   next = (current ÷ 256) × 128   (halve then floor to nearest 128)
 *   include only while current > TILE_SIZE (512)
 *
 * panoTiles: CSV string — "512,<l1>,<l2>,…,<lN>"  (krpano multires attribute)
 */
class KrpanoTiler extends TilerBase
{
	private const TILE_SIZE = 512;

	/** @var int[] Ascending list of tiled level sizes */
	private array $levelSizes = [];

	// -------------------------------------------------------------------------
	// Level-size computation
	// -------------------------------------------------------------------------

	/**
	 * Compute ascending level sizes from the cube face pixel width.
	 *
	 * Mirrors krpano.py compute_level_sizes():
	 *   1. max = round(faceWidth / 128) × 128
	 *   2. next = (current ÷ 256) × 128
	 *   3. include while current > TILE_SIZE
	 */
	private function computeLevelSizes(int $faceWidth): void
	{
		$maxLevel = (int)round($faceWidth / 128) * 128;
		$levels   = [];
		$current  = $maxLevel;

		while ($current > self::TILE_SIZE) {
			$levels[] = $current;
			$current  = (int)($current / 256) * 128; // halve, floor to nearest 128
		}

		sort($levels); // ascending: smallest → largest
		$this->levelSizes = $levels;
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
	 *     panoTiles: string
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

		// krpano multires attribute: TILE_SIZE prepended to the level list
		$panoTiles = self::TILE_SIZE . ',' . implode(',', $this->levelSizes);

		$this->generateOgImage($facesDir, $outDir);
		$this->generatePreview($facesDir, $outDir);
		$this->generateThumb($facesDir, $outDir);

		return [
			'cubeResolution' => $cubeResolution,
			'maxLevel'       => count($this->levelSizes),
			'tileResolution' => self::TILE_SIZE,
			'panoTiles'      => $panoTiles,
		];
	}

	private function generateThumb(string $facesDir, string $outDir): void
	{
		$src     = $this->loadImage($this->facePath($facesDir, 'f'));
		$resized = $this->resizeImage($src, 240, 240);
		$this->destroyImage($src);
		$this->saveFullImage($resized, $outDir . '/thumb.jpg');
		$this->destroyImage($resized);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Resize $src to $levelSize and write krpano-format JPEG tiles.
	 *
	 * krpano path: {outDir}/{face}/l{li}/{row:02d}/l{li}_{face}_{row:02d}_{col:02d}.jpg
	 * Rows and cols are 1-based with 2-digit zero-padding.
	 * Edge tiles are allowed (levelSize may not be a multiple of TILE_SIZE).
	 */
	private function writeTiles(
		mixed  $src,
		string $face,
		int    $li,
		int    $levelSize,
		string $outDir
	): void {
		$resized = $this->resizeImage($src, $levelSize, $levelSize);

		$nFull  = intdiv($levelSize, self::TILE_SIZE);
		$nTotal = $nFull + ($levelSize % self::TILE_SIZE !== 0 ? 1 : 0);

		for ($row = 0; $row < $nTotal; $row++) {
			$rowNum = $row + 1;
			$rowStr = sprintf('%02d', $rowNum);

			$rowDir = implode(DIRECTORY_SEPARATOR, [
				rtrim($outDir, '/\\'),
				$face,
				"l{$li}",
				$rowStr,
			]);
			$this->ensureDir($rowDir);

			$y0 = $row * self::TILE_SIZE;
			$h  = min(self::TILE_SIZE, $levelSize - $y0);

			for ($col = 0; $col < $nTotal; $col++) {
				$colNum = $col + 1;
				$colStr = sprintf('%02d', $colNum);

				$x0   = $col * self::TILE_SIZE;
				$w    = min(self::TILE_SIZE, $levelSize - $x0);
				$dest = $rowDir . DIRECTORY_SEPARATOR . "l{$li}_{$face}_{$rowStr}_{$colStr}.jpg";
				$this->cropAndSave($resized, $x0, $y0, $w, $h, $dest);
			}
		}

		$this->destroyImage($resized);
	}
}
