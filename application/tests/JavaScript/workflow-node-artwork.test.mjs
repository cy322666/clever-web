import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

function fixture() {
    const images=[];
    const window={addEventListener() {}};
    class Image {
        constructor() { images.push(this); }
        decode() { return Promise.resolve(); }
    }
    runInNewContext(readFileSync(new URL('../../resources/js/app.js',import.meta.url),'utf8'), {
        window,Image,document:{addEventListener() {}},
        getComputedStyle:()=>({getPropertyValue:()=> 'url("/build/assets/approved.png")'}),
    });
    const node=()=>Object.assign(window.workflowNodeArtwork(),{$el:{}});
    return {node,images};
}

test('nodes keep their inline glyph while the shared image loads and decodes',async()=>{
    const {node,images}=fixture();const first=node(),second=node();
    const pending=[first.init(),second.init()];
    assert.equal(images.length,1,'All nodes must share one artwork request');
    assert.equal(first.artworkLoaded,false);assert.equal(second.artworkLoaded,false);
    let decoded;images[0].decode=()=>new Promise(resolve=>decoded=resolve);
    const loading=images[0].onload();
    assert.equal(first.artworkLoaded,false,'A completed download alone is not a decoded image');
    decoded();await loading;await Promise.all(pending);
    assert.equal(first.artworkLoaded,true);assert.equal(second.artworkLoaded,true);
    const later=node();await later.init();
    assert.equal(later.artworkLoaded,true);assert.equal(images.length,1);
});

test('failed artwork leaves the inline glyph visible and never blocks editing',async()=>{
    const {node,images}=fixture();const card=node(),pending=card.init();
    images[0].onerror();await pending;
    assert.equal(card.artworkLoaded,false);
});

test('a decode error leaves the inline glyph visible',async()=>{
    const {node,images}=fixture();const card=node(),pending=card.init();
    images[0].decode=()=>Promise.reject(new Error('decode failed'));
    await images[0].onload();await pending;
    assert.equal(card.artworkLoaded,false);
});
