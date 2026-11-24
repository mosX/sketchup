<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogMaterialRequest;
use App\Http\Resources\CatalogMaterialResource;
use App\Models\CatalogMaterial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogMaterialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CatalogMaterialResource::collection($request->user()->catalogMaterials()->orderBy('name')->orderBy('id')->get());
    }

    public function store(CatalogMaterialRequest $request): CatalogMaterialResource
    {
        return new CatalogMaterialResource($request->user()->catalogMaterials()->create($request->validated()));
    }

    public function update(CatalogMaterialRequest $request, CatalogMaterial $catalogMaterial): CatalogMaterialResource
    {
        $catalogMaterial->update($request->validated());

        return new CatalogMaterialResource($catalogMaterial);
    }
}
