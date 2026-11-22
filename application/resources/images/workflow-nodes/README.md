# Original approved artwork

`duotone-approved.png` and the generated `approved-glyphs-*.svg` sprites preserve
the previous approved artwork. They are kept unchanged as source material.

The current editor renders inline outline SVGs from `resources/svg/workflow-nodes`
through `workflow-node-icon.blade.php`. It uses one opaque color per node, hides
duotone backing fills, and keeps the entity and operation cues distinct.
No raster atlas, external sprite, SVG mask or image filter is loaded by the nodes.
The JavaScript node retains its `JS` lettermark. Node backgrounds stay neutral.

To regenerate the archived sprites with PHP GD:

```sh
php scripts/build-workflow-approved-glyphs.php
```
