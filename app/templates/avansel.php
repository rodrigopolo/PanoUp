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

	<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no, minimal-ui" />
	<style>@-ms-viewport { width: device-width; }</style>
	<link rel="stylesheet" href="<?=$siteRoot;?>public/avansel.0.0.17/style.css"/>
</head>
<body>
	
	<div id="pano"></div>

	<!-- Avansel's public API has no way to set an initial view/heading, so
	     Photo Sphere XMP-GPano initial-view metadata is ignored for this viewer. -->
	<script type="text/javascript">
		const panorama = {
			prefix: "<?=$siteRoot;?><?=$panoImage;?>",
			domid: "pano",
			tiles: <?=$panoTiles;?>
		}
	</script>
	<script async src="//unpkg.com/es-module-shims@2.8.1/dist/es-module-shims.js"></script>
	<script type="importmap">{"imports":{"avansel":"https://unpkg.com/avansel@0.0.17/build/avansel.js"}}</script>
	<script type="module" src="<?=$siteRoot;?>public/avansel.0.0.17/main.js"></script>

</body>
</html>
