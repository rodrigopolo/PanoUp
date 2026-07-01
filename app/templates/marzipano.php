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
	<link rel="stylesheet" href="<?=$siteRoot;?>public/marzipano.0.10.2/style.css">
</head>
<body>

	<div id="pano"></div>

	<script type="text/javascript">
		var panorama = {
			prefix: "<?=$siteRoot;?><?=$panoImage;?>",
			domid: "pano",
			tiles:<?=$panoTiles;?>,
			initialYaw: <?=$panoInitialYaw ?? 0;?>,
			initialPitch: <?=$panoInitialPitch ?? 0;?>,
			initialHfov: <?=$panoInitialHfov ?? 90;?>,
		}
	</script>
	<script src="//cdnjs.cloudflare.com/ajax/libs/marzipano/0.10.2/marzipano.min.js" integrity="sha512-yXzJzoGCljUpxjkFmg+6No2leY9Dp0/PpQiVkIQ+uZLAb5xwsTAY2I5l/Wm7rmjDk0nRh3Q2Cr5T5cSh1OHJBw==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
	<script src="<?=$siteRoot;?>public/marzipano.0.10.2/main.js"></script>

</body>
</html>
