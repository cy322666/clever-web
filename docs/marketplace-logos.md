# Marketplace Brand Assets

Retrieved from official service websites on 2026-09-28. These marks identify
integrated services; their ownership remains with the respective brands.

Assets are served locally from `application/public/logo/integrations/20260928/`.
Use a new versioned directory when updating them so long-lived browser caches
cannot retain the old artwork. Do not replace service marks with generic icons
or recolor them to match the application theme.

| File | Official source | Asset source |
| --- | --- | --- |
| `tilda.svg` | https://tilda.cc/mediakit/ | https://static.tildacdn.net/tild3063-6363-4339-a138-336166373032/logo___1.svg |
| `yclients.png` | https://www.yclients.ru/ | https://www.yclients.ru/images/tild3935-6531-4164-b063-666632626235__group_513378.png |
| `sqns.svg` | https://sqns.ru/ | https://static.tildacdn.com/tild6165-3536-4137-b765-326136323765/_.svg |
| `vetmanager.svg` | https://vetmanager.ru/ | Inline SVG logo in the site's header image |
| `excel.svg` | https://www.microsoft.com/en-us/microsoft-365/excel | https://www.microsoft.com/content/dam/microsoft/bade/images/icons/en-us/m365-app-icons-fy26/Excel-Icon-FY26.svg |

The Vetmanager symbol is the second top-level group in its official header SVG.
Only the wordmark group was omitted and the viewBox narrowed to `0 0 26.74 26.74`;
the symbol's paths, proportions and colors are unchanged.

## Original Widget Icons

Clever's own widgets use individually drawn SVG icons in
`application/public/logo/widgets/20260928/`, not third-party logos or stock
icon-library glyphs. They share a 96-unit canvas, rounded tile, subtle highlight
and solid foreground shapes that remain readable at the 48-pixel card size.

| Widget | File | Visual meaning |
| --- | --- | --- |
| Distribution | `distribution.svg` | A deal card branching into three destinations; coral palette |
| Workflows | `workflows.svg` | Connected start, action and completion steps; teal palette |
| Response Control | `response-control.svg` | A conversation with a response timer; blue and amber palette |
