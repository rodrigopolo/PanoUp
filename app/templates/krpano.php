<?php
// Variables set by template_loader.php
?><!DOCTYPE html>
<html>
<head>
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

	<meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, viewport-fit=cover" />
	<meta name="apple-mobile-web-app-capable" content="yes" />
	<meta name="apple-mobile-web-app-status-bar-style" content="black" />
	<meta name="mobile-web-app-capable" content="yes" />
	<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
	<meta http-equiv="x-ua-compatible" content="IE=edge" />
	<?php if (defined('KRPANO_DIR')): ?>
	<link href="<?=$siteRoot;?>public/<?=KRPANO_DIR;?>/style.css" rel="stylesheet">
	<?php endif; ?>
</head>
<body>
	<?php if (!defined('KRPANO_DIR')): ?>
	<table style="width:100%;height:100%;">
		<tr style="vertical-align:middle;">
			<td>
				<div style="text-align:center;">ERROR:<br/>
					<br/>
					KRPANO_DIR is not defined in app/config.php<br/>
					<br/>
				</div>
			</td>
		</tr>
	</table>
	<?php else: ?>
	<script src="<?=$siteRoot;?>public/<?=KRPANO_DIR;?>/tour.js"></script>
	<div id="pano" style="width:100%;height:100%;">
		<noscript>
			<table style="width:100%;height:100%;">
				<tr style="vertical-align:middle;">
					<td>
						<div style="text-align:center;">ERROR:<br/>
							<br/>
							Javascript not activated<br/>
							<br/>
						</div>
					</td>
				</tr>
			</table>
		</noscript>
		<script>
			embedpano({
				xml: "tour.xml",
				basepath: "<?=$siteRoot;?>public/<?=KRPANO_DIR;?>/"
			});
		</script>
	</div>
	<?php endif; ?>
</body>
</html>

