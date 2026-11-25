import { test } from 'node:test';
import assert from 'node:assert/strict';
import * as THREE from 'three';
import { exportPresentationPng, resolvePresentationLighting } from '../../resources/js/editor/woodPresentation.js';
import { createPresentationExportModel, exportPresentationModel } from '../../resources/js/editor/presentationExport.js';

test('render lighting controls change exposure and light independently', () => {
    const studio = resolvePresentationLighting({ light: 'studio' });
    const adjusted = resolvePresentationLighting({ light: 'studio', exposure: -1, keyPower: 50, fillPower: 0, azimuth: 180 });

    assert.ok(studio.exposure < 1);
    assert.equal(adjusted.exposure, 0.5);
    assert.equal(adjusted.intensity, studio.intensity / 2);
    assert.equal(adjusted.environment, 0);
    assert.ok(Math.abs(adjusted.direction.y - studio.direction.y) < 1e-9);
    assert.ok(Math.abs(adjusted.direction.x + studio.direction.x) < 1e-9);
    assert.ok(Math.abs(adjusted.direction.z + studio.direction.z) < 1e-9);
});

test('OBJ keeps active triangles, names and millimeter dimensions on mirrored parts', async () => {
    const model = new THREE.Group();
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute([0, 0, 0, 1, 0, 0, 0, 1, 0, 1, 1, 0], 3));
    geometry.setIndex([0, 1, 2, 2, 1, 3]);
    geometry.setDrawRange(0, 3);
    const mirror = new THREE.Mesh(geometry);
    mirror.name = 'Board #1';
    mirror.position.x = 2;
    mirror.scale.x = -1;
    const hidden = new THREE.Mesh(geometry);
    hidden.name = 'Hidden';
    hidden.visible = false;
    model.add(mirror, hidden);

    const obj = await (await exportPresentationModel(model, 'obj')).text();

    assert.match(obj, /^o Board _1\ng Board__1$/m);
    assert.match(obj, /^v 200 0 0$/m);
    assert.match(obj, /^v 100 0 0$/m);
    assert.deepEqual(obj.match(/^f .*$/gm), ['f 1 3 2']);
    assert.doesNotMatch(obj, /Hidden/);
    assert.deepEqual(Array.from(geometry.index.array), [0, 1, 2, 2, 1, 3]);
});

test('GLB export uses meters, shares repeated geometry, and maps both side and end grain', () => {
    const model = new THREE.Group();
    const geometry = new THREE.BoxGeometry(4, 0.3, 1);
    const finish = new THREE.MeshPhysicalMaterial({ roughness: 0.47, clearcoat: 0.12 });
    const beam = new THREE.Mesh(geometry, finish);
    const crossgrain = beam.clone();
    crossgrain.userData.grainAlongWidth = true;
    const fastener = new THREE.Mesh(new THREE.CylinderGeometry());
    fastener.userData.hardware = true;
    model.add(beam, beam.clone(), crossgrain, fastener);
    const woodMaps = { grain: new THREE.Texture(), end: new THREE.Texture() };
    const { root, dispose } = createPresentationExportModel(model, 'glb', woodMaps);

    try {
        const [first, repeat, cross, metal] = root.children;
        assert.equal(root.scale.x, 0.1);
        assert.equal(first.geometry, repeat.geometry);
        assert.notEqual(first.geometry, cross.geometry);
        assert.equal(first.geometry.getAttribute('uv').count, 36);
        assert.deepEqual(first.geometry.groups.map(({ count }) => count), [24, 12]);
        assert.equal(first.material[0].map, woodMaps.grain);
        assert.equal(first.material[1].map, woodMaps.end);
        assert.equal(first.material[0].clearcoat, 0.12);
        assert.equal(metal.material, fastener.material);
        assert.equal(beam.material.map, null);
    } finally {
        dispose();
    }
});

test('PNG capture preserves the camera and restores the editor resolution', async () => {
    const rendered = [];
    const scene = {};
    const camera = {};
    let resolution = [3000, 1500];
    let pixelRatio = 1.5;
    const png = new Blob(['png'], { type: 'image/png' });
    const renderer = {
        getSize: target => target.set(...resolution),
        getPixelRatio: () => pixelRatio,
        setPixelRatio: next => { pixelRatio = next; },
        setSize: (width, height, updateStyle) => {
            assert.equal(updateStyle, false);
            resolution = [width, height];
        },
        render: (currentScene, currentCamera) => rendered.push({ currentScene, currentCamera, resolution, pixelRatio }),
        domElement: { toBlob: (callback, type) => {
            assert.equal(type, 'image/png');
            queueMicrotask(() => callback(png));
        } },
    };

    assert.equal(await exportPresentationPng(renderer, scene, camera), png);
    assert.deepEqual(rendered, [{ currentScene: scene, currentCamera: camera, resolution: [4096, 2048], pixelRatio: 1 }]);
    assert.deepEqual(resolution, [3000, 1500]);
    assert.equal(pixelRatio, 1.5);

    renderer.domElement.toBlob = callback => callback(null);
    await assert.rejects(exportPresentationPng(renderer, scene, camera), /PNG export failed/);
    assert.deepEqual(resolution, [3000, 1500]);
    assert.equal(pixelRatio, 1.5);
});
