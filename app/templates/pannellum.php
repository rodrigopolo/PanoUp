<?php
// Variables set by template_loader.php
?><!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title><?=$imageTitle;?></title>
	<meta name="description" content="<?=$imageDescription;?>">

	<!-- Open Graph -->
	<meta property="og:title" content="<?=$imageTitle;?>" />
	<meta property="og:description" content="<?=$imageDescription;?>" />
	<meta property="og:image" content="<?=$siteRoot;?><?=$panoImage;?>og_image.jpg" />
	<meta property="og:url" content="<?=$panoURL;?>" />
	<meta property="og:type" content="website" />

	<!-- Twitter -->
	<meta name="twitter:card" content="summary_large_image" />
	<meta name="twitter:title" content="<?=$imageTitle;?>" />
	<meta name="twitter:description" content="<?=$imageDescription;?>" />
	<meta name="twitter:image" content="<?=$siteRoot;?><?=$panoImage;?>og_image.jpg" />

	<meta name="viewport" content="target-densitydpi=device-dpi, width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no, minimal-ui" />
	<style>@-ms-viewport { width: device-width; }</style>
	<link rel="stylesheet" href="//cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.min.css">
	<link rel="stylesheet" href="<?=$siteRoot;?>public/pannellum.2.5.7/style.css"/>
</head>
<body>

	<div id="pano"></div>

	<script src="//cdn.jsdelivr.net/npm/pannellum@2.5.7/build/pannellum.min.js"></script>
	<script>
		var panorama = {
			"autoLoad": true,
			"type": "multires",
			"preview": "<?=$siteRoot;?><?=$panoImage;?>/1/f_0_0.jpg",
			"minHfov": 10,
			"maxHfov": 140,
			"hfov": <?=$panoInitialHfov ?? 90;?>,
<?php if ($panoInitialYaw !== null): ?>
			"yaw": <?=$panoInitialYaw;?>,
<?php endif; ?>
<?php if ($panoInitialPitch !== null): ?>
			"pitch": <?=$panoInitialPitch;?>,
<?php endif; ?>
<?php if ($panoHorizonPitch !== null): ?>
			"horizonPitch": <?=$panoHorizonPitch;?>,
<?php endif; ?>
<?php if ($panoHorizonRoll !== null): ?>
			"horizonRoll": <?=$panoHorizonRoll;?>,
<?php endif; ?>
<?php if ($panoHeading !== null): ?>
			"compass": true,
			"northOffset": <?=$panoHeading;?>,
<?php endif; ?>
			"multiResMinHfov": true,
			"multiRes": {
				"basePath": "<?=$siteRoot;?><?=$panoImage;?>",
				"path": "/%l/%s_%y_%x",
				"fallbackPath": "/fallback/%s",
				"extension": "jpg",
				"tileResolution": <?=$tileResolution;?>,
				"maxLevel": <?=$maxLevel;?>,
				"cubeResolution": <?=$cubeResolution;?>
			},
			domid: "pano"
		}
		pannellum.viewer(panorama.domid, panorama);
	</script>

</body>
</html>

