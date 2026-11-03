<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Services\Inventory\InventoryClientInterface;
use App\Services\Inventory\InventoryException;
use App\Support\ApiResponse;
use App\Support\ContractResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function __construct(private readonly InventoryClientInterface $inventory) {}

    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->latest();

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        $products = $query->paginate(
            perPage: min(max((int) $request->input('per_page', 15), 1), 100),
        );

        $productIds = collect($products->items())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        try {
            $stockByProductId = $this->inventory->getQuantities($productIds);
        } catch (InventoryException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }

        return response()->json([
            'products' => ContractResponse::productList($products->items(), $stockByProductId),
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        try {
            $stock = $this->inventory->getQuantity($product->id);
        } catch (InventoryException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }

        return response()->json([
            'success' => true,
            'product' => ContractResponse::product($product, $stock),
        ]);
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

        return ApiResponse::success(
            ['product' => ProductResource::make($product)],
            'Product created',
            201,
        );
    }
}
