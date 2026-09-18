export const disposeSceneObjects = objects => {
    const geometries = new Set(), materials = new Set();
    objects.forEach(object => object.traverse(child => {
        if (child.geometry) geometries.add(child.geometry);
        if (child.material) (Array.isArray(child.material) ? child.material : [child.material]).forEach(material => materials.add(material));
    }));
    geometries.forEach(geometry => geometry.dispose());
    materials.forEach(material => material.dispose());
};

export const applyMeshAppearance = (mesh, { selected, ghosted, dimmed }) => {
    const transparent = ghosted || dimmed;
    const setTransparency = (material, opacity) => {
        if (material.transparent !== transparent) material.needsUpdate = true;
        material.transparent = transparent;
        material.opacity = opacity;
    };
    mesh.material.emissive.set(selected ? '#315f4a' : '#000000');
    mesh.material.emissiveIntensity = selected ? 0.24 : 0;
    setTransparency(mesh.material, dimmed ? 0.07 : ghosted ? 0.2 : 1);
    mesh.material.depthWrite = !transparent;
    mesh.renderOrder = transparent ? 1 : 0;
    const edges = mesh.userData.edges;
    if (edges) {
        edges.material.color.set(selected ? '#173d30' : '#6f5235');
        setTransparency(edges.material, dimmed ? 0.08 : ghosted ? 0.32 : 1);
    }
};
