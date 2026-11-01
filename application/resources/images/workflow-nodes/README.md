# Approved node artwork

`duotone-approved.png` is the original generated image explicitly selected by the
user (third displayed option), supplied again on 2026-10-13. It is used unchanged,
not redrawn with a different icon library.

The CSS atlas windows are measured 194 × 194 square areas inside the twelve tiles,
not including captions or canvas. The original is 1487 × 1058 pixels. At the
editor's 96px node size this supplies 2x artwork. The atlas is loaded once by Vite
with a content hash; the ports, captions, state badges and edges remain live UI.
Disabled nodes grayscale only the artwork. Nodes not present in the approved
sheet retain their existing library fallback, with distinct entity/action cues.

The JavaScript node uses an explicit `JS` lettermark instead of its atlas tile,
as requested by the user. The other selected icons are unchanged.

The condition tile is also embedded losslessly in `workflow-condition-artwork.css`
(194 x 194 pixels at x=494, y=444). It paints from the loaded stylesheet and never
switches from a different SVG while waiting for the shared atlas to download.
