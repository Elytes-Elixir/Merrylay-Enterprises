# PWA App Icons

This directory should contain the app icons for the Progressive Web App (PWA).

## Required Icons

The following icon sizes are required for the PWA manifest:

- icon-72x72.png
- icon-96x96.png
- icon-128x128.png
- icon-144x144.png
- icon-152x152.png
- icon-192x192.png
- icon-384x384.png
- icon-512x512.png

## Icon Design Guidelines

- **Primary Color**: #1e293b (dark blue/slate)
- **Logo**: Store icon (fa-store from Font Awesome)
- **Style**: Clean, modern, professional
- **Background**: Solid color with centered icon
- **Format**: PNG with transparency support

## How to Generate Icons

### Option 1: Using Online Tools
1. Visit https://realfavicongenerator.net/
2. Upload your base icon (at least 512x512)
3. Select the sizes needed
4. Download and extract to this directory

### Option 2: Using ImageMagick (Command Line)
```bash
# Install ImageMagick first
# Then run:
convert icon-base.png -resize 72x72 icon-72x72.png
convert icon-base.png -resize 96x96 icon-96x96.png
convert icon-base.png -resize 128x128 icon-128x128.png
convert icon-base.png -resize 144x144 icon-144x144.png
convert icon-base.png -resize 152x152 icon-152x152.png
convert icon-base.png -resize 192x192 icon-192x192.png
convert icon-base.png -resize 384x384 icon-384x384.png
convert icon-base.png -resize 512x512 icon-512x512.png
```

### Option 3: Using Photoshop/GIMP
1. Create a 512x512 canvas
2. Add your logo/icon design
3. Export at different sizes using "Save for Web"
4. Ensure all sizes are saved as PNG

## Temporary Placeholder

Until proper icons are created, the PWA will use browser defaults. The app will still function correctly, but won't have custom icons.

## Next Steps

1. Create or obtain a base icon (512x512 minimum)
2. Generate all required sizes
3. Place them in this directory
4. Test PWA installation on mobile devices
