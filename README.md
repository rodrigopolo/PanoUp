# PanoUp

A self-hosted web tool for uploading and publishing equirectangular panoramas.
Drop a 2:1 JPG, choose a viewer, and the app tiles and publishes it instantly.

## What it is

A simple app that runs on any standard LAMP server and needs no cloud service or
external API. You upload a panorama, it processes it, and you get a shareable
URL. All data stays on your own server.

## How it works

The browser validates the image, extracts EXIF GPS coordinates, and uses WebGL
to render six cube faces from the equirectangular source. Each face is uploaded
to the server, where PHP tiles it into a multires pyramid using Imagick
(preferred) or GD as a fallback. The server also generates an Open Graph image
(`og_image.jpg`), a preview strip (`preview.jpg`), and a thumbnail
(`thumb.jpg`) before writing a `meta.json` manifest. A small PHP router serves
the correct viewer template for each published panorama URL.

## Features

- **Password protection** — create `password.txt` to enable, delete it to disable. No tools or hashing needed.
- **EXIF GPS** — latitude and longitude are extracted automatically and stored with each panorama.
- **Derived images** — Open Graph image (1200×630), krpano preview strip (256×1536), and thumbnail (240×240) are generated on upload.
- **Flexible folder names** — panorama folders can be renamed to anything, including names with spaces.
- **Configurable defaults** — set your preferred viewer and library paths in `app/config.php`.

## Requirements

- Apache with `mod_rewrite` enabled (`AllowOverride All` in your vhost)
- PHP 8.0+ with the **Imagick** extension (recommended) or **GD**
- A standard LAMP / MAMP / WAMP stack

## Installation

1. Clone or download into your web server's document root.
2. Confirm `mod_rewrite` is enabled and `.htaccess` overrides are allowed.
3. *(Optional)* Open `app/config.php` to set your default viewer and, if you
   are using krpano, upload your krpano files, update `KRPANO_DIR` to match your
   installed version, more info below.
4. *(Optional)* Create `password.txt` in the project root folder and type a
   password in plain text into it to restrict access to the upload form.
5. Visit the site root — the upload form is ready.

## Supported Viewers

| Viewer                                 | License    | Notes                                                                                                                         |
| -------------------------------------- | ---------- | ----------------------------------------------------------------------------------------------------------------------------- |
| [Pannellum](https://pannellum.org)     | MIT        | Default; loaded from CDN                                                                                                      |
| [Marzipano](https://www.marzipano.net) | Apache 2.0 | Loaded from CDN                                                                                                               |
| [Avansel](https://avansel.github.io/)  | MIT        | Loaded from CDN                                                                                                               |
| [Krpano](https://krpano.com)           | Commercial | **Not included** — obtain a license, place the library in `public/krpano.X.X.X/`, and update `KRPANO_DIR` in `app/config.php` |

For example, if you want to use krpano version 1.23.3 and have a licensed
version, put the following files into in the `./public/krpano.1.23.3` folder:

```
├── plugins
│   ├── bingmaps.js
│   ├── combobox.xml
│   ├── fps.xml
│   ├── googlemaps.js
│   ├── gyro2.js
│   ├── krpanomaps.xml
│   ├── pp_blur.js
│   ├── pp_light.js
│   ├── pp_sharpen.js
│   ├── showtext.xml
│   ├── soundinterface.js
│   ├── videoplayer.js
│   ├── webvr_handcursor.png
│   ├── webvr_laser.png
│   ├── webvr_light.png
│   ├── webvr_vrcursor.png
│   ├── webvr.js
│   └── webvr.xml
├── skin
│   ├── rotate_device.png
│   ├── vtourskin_design_ultra_light.xml
│   ├── vtourskin_hotspot.png
│   ├── vtourskin_light.png
│   ├── vtourskin_mapspot.png
│   ├── vtourskin_mapspotactive.png
│   ├── vtourskin.png
│   └── vtourskin.xml
├── style.css <-- You'll have to create this file with tour own customizations.
└── tour.js
```

## License

MIT License — Copyright © 2026 Rodrigo Polo, Vibe coding with Claude.
