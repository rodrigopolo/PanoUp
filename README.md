# PanoUp

A plug-and-play self-hosted web tool for uploading and publishing
equirectangular panoramas. Drop a 2:1 JPG, choose a viewer, and the app tiles
and publishes it instantly.

A simple app that runs on any standard LAMP server and needs no cloud service,
external API or database connection. You upload a panorama, it processes it, and
you get a shareable URL. All data stays on your own server.

## How it works

The browser validates the image, extracts full EXIF metadata (camera make/model,
exposure settings, GPS coordinates, and more), and uses WebGL to render six cube
faces from the equirectangular source. Each face is uploaded to the server one at
a time while the upload form reports byte-level progress. Once all six faces are
uploaded, the server spawns a detached background worker and immediately returns
the panorama URL - no waiting for tiles to finish.

Each panorama gets a short random ID (e.g. `dQw4w9Wg`) rather than a sequential
integer, so URLs are not guessable by enumeration. The background worker tiles
each face into a multires pyramid using Imagick (preferred) or GD as a fallback,
generates an Open Graph image (`og_image.jpg`), a preview strip (`preview.jpg`),
and a thumbnail (`thumb.jpg`), then finalises a single `meta.json` manifest that
tracks both job state and panorama metadata. If the panorama URL is visited before
the worker finishes, a live progress page is served that polls the worker status
and auto-reloads once processing is complete. A small PHP router serves the
correct viewer template for each published panorama URL.

## Features

- **Background tiling** - tile generation runs in a detached server worker; the panorama URL is available immediately after upload.
- **Live processing page** - visiting the panorama URL while tiles are being generated shows a real-time progress page that auto-reloads when done.
- **Password protection** - create `password.txt` to enable, delete it to disable. No tools or hashing needed.
- **Full EXIF metadata** - camera make/model, exposure settings, GPS coordinates, and all other available EXIF fields are extracted in the browser and stored with each panorama.
- **XMP-GPano initial view & heading** - if the uploaded JPG carries Photo Sphere XMP-GPano tags (`PoseHeadingDegrees`, `PosePitchDegrees`, `PoseRollDegrees`, `InitialViewHeadingDegrees`, `InitialViewPitchDegrees`, `InitialViewRollDegrees`, `InitialHorizontalFOVDegrees`), all four viewers open at the recommended initial view. Pannellum, krpano, and Avansel also correct the Pose pitch/roll horizon tilt directly in the rendered sphere, and Pannellum/krpano additionally orient a compass/map heading indicator. Marzipano has neither, so Pose is instead folded into its starting view only (panning away reveals the uncorrected tilt).
- **Non-enumerable URLs** - each panorama gets a short random ID (`dQw4w9Wg` style) instead of a sequential integer, so the full library cannot be discovered by guessing.
- **Derived images** - Open Graph image (1200×630), krpano preview strip (256×1536), and thumbnail (240×240) are generated on upload.
- **Flexible folder names** - panorama folders can be renamed to anything, including names with spaces.
- **Configurable defaults** - set your preferred viewer and library paths in `app/config.php`.
- **Responsive UI** - upload form works on both desktop and mobile browsers.
- **Screen Wake Lock** - prevents the screen from sleeping during WebGL rendering and upload (Chrome/Edge/Safari; silently skipped on Firefox).

## Requirements

- Apache with `mod_rewrite` enabled (`AllowOverride All` in your vhost)
- PHP 8.0+ with the **Imagick** extension (recommended) or **GD**
- A standard LAMP / MAMP / WAMP stack

Large panoramas produce large per-face uploads (a 20000px-wide source can
easily exceed 8MB per face), so `.htaccess` ships `php_value` overrides
raising `post_max_size`/`upload_max_filesize`/`memory_limit` — but this only
takes effect under **mod_php**. On php-fpm/CGI hosts (where `.htaccess`
`php_value` is ignored or fatal), or where `AllowOverride` doesn't permit
`php_value`, you'll need to raise these instead via `php.ini`, a `.user.ini`
file, your php-fpm pool config, or by asking your host. If the limit is ever
exceeded, the app now reports a specific "Upload too large" error rather
than a generic failure.

## Installation

1. Clone or download into your web server's document root.
2. Confirm `mod_rewrite` is enabled and `.htaccess` overrides are allowed.
3. *(Optional)* Open `app/config.php` to set your default viewer and, if you
   are using krpano, upload your krpano files, update `KRPANO_DIR` to match your
   installed version, more info below.
4. *(Optional)* Create `password.txt` in the project root folder and type a
   password in plain text into it to restrict access to the upload form.
5. Visit the site root - the upload form is ready.

## Supported Viewers

| Viewer                                 | License    | Notes                                                                                                                                                                                                              |
| -------------------------------------- | ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| [Pannellum](https://pannellum.org)     | MIT        | Default; loaded from CDN. Full XMP-GPano support: initial view, compass heading, horizon tilt correction                                                                                                           |
| [Marzipano](https://www.marzipano.net) | Apache 2.0 | Loaded from CDN. Supports XMP-GPano initial view (Pose folded into the starting view only); no compass/heading indicator or sphere-wide tilt correction                                                            |
| [Avansel](https://avansel.github.io/)  | MIT        | Loaded from CDN. Supports XMP-GPano initial view and horizon pitch/roll correction; no compass/heading indicator                                                                                                   |
| [Krpano](https://krpano.com)           | Commercial | **Not included** - obtain a license, place the library in `public/krpano.X.X.X/`, and update `KRPANO_DIR` in `app/config.php`. Supports XMP-GPano initial view, compass heading, and horizon pitch/roll correction |

For example, if you want to use krpano version 1.23.3 and have a licensed
version, put the following files into in the `./public/krpano.1.23.3` folder:

```
├── plugins <-- The complete plug-ins folder
│   ├── bingmaps.js
│   ├── googlemaps.js
│   ├── gyro2.js
│   ├── krpanomaps.xml
│   ├── ...
│   ├── webvr.js
│   └── webvr.xml
├── skin <-- The complete skins folder
│   ├── rotate_device.png
│   ├── vtourskin_design_ultra_light.xml
│   ├── ...
│   ├── vtourskin.png
│   └── vtourskin.xml
├── style.css <-- Your own customizations, else, leave it blank.
└── tour.js <-- Your licensed krpano
```

## License

MIT License - Copyright © 2026 Rodrigo Polo.
