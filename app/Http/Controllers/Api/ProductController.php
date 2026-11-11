<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Services\Cache\ProductCacheService;
use App\Services\Inventory\InventoryClientInterface;
use App\Services\Inventory\InventoryException;
use App\Support\ApiResponse;
use App\Support\ContractResponse;
use App\Support\ForcesPrimaryRead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    use ForcesPrimaryRead;

    public function __construct(
        private readonly InventoryClientInterface $inventory,
        private readonly ProductCacheService $productCache,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $category = $request->filled('category')
            ? $request->string('category')->toString()
            : '';
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $page = max((int) $request->input('page', 1), 1);

        $payload = $this->productCache->rememberList($category, $page, $perPage, function () use ($request, $category, $perPage) {
            $query = Product::query()->latest();

            if ($category !== '') {
                $query->where('category', $category);
            }

            $products = $query->paginate(perPage: $perPage);

            $productIds = collect($products->items())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $stockByProductId = $this->inventory->getQuantities($productIds);

            return [
                'products' => ContractResponse::productList($products->items(), $stockByProductId),
                'meta' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
            ];
        });

        $etag = $this->makeEtag($payload);

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'private, must-revalidate');
        }

        return response()->json($payload)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, must-revalidate');
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        $payload = $this->productCache->rememberProduct($product->id, function () use ($product) {
            try {
                $stock = $this->inventory->getQuantity($product->id);
            } catch (InventoryException $exception) {
                throw $exception;
            }

            return [
                'success' => true,
                'product' => ContractResponse::product($product, $stock),
            ];
        });

        $etag = $this->makeEtag($payload);

        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'private, must-revalidate');
        }

        return response()->json($payload)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, must-revalidate');
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = Storage::disk('public')->url(
                $request->file('image')->store('products', 'public'),
            );
        }

        $product = Product::query()->create([
            'name' => $request->string('name')->toString(),
            'description' => $request->string('description')->toString(),
            'price' => $request->input('price'),
            'category' => $request->string('category')->toString(),
            'stock' => 0,
            'image_url' => $imagePath,
        ]);

        try {
            $this->inventory->setQuantity(
                $product->id,
                (int) $request->input('initial_quantity'),
            );
            $product->update(['stock' => (int) $request->input('initial_quantity')]);
            $product->available_quantity = (int) $request->input('initial_quantity');
        } catch (InventoryException $exception) {
            $product->delete();
            return ApiResponse::error($exception->getMessage(), $exception->httpStatus, [
                'code' => $exception->errorCode,
            ]);
        }
        
        $this->productCache->forgetAll();
        $this->forcePrimaryConnection();

        return ApiResponse::success(
            ['product' => ProductResource::make($product)],
            'Product created',
            201,
        );
    }

    private function makeEtag(array $payload): string
    {
        return '"' . md5(json_encode($payload)) . '"';
    }
}