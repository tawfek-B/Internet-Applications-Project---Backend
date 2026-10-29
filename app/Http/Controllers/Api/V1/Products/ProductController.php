<?php

namespace App\Http\Controllers\Api\V1\Products;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Services\Inventory\InventoryClientInterface;
use App\Services\Inventory\InventoryException;
use App\Support\ApiResponse;
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

        $products->getCollection()->transform(function (Product $product) {
            try {
                $product->available_quantity = $this->inventory->getQuantity($product->id);
            } catch (InventoryException) {
                $product->available_quantity = null;
            }

            return $product;
        });

        return ApiResponse::success([
            'products' => ProductResource::collection($products->items()),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        try {
            $product->available_quantity = $this->inventory->getQuantity($product->id);
        } catch (InventoryException $exception) {
            return ApiResponse::error($exception->getMessage(), $exception->httpStatus, [
                'code' => $exception->errorCode,
            ]);
        }

        return ApiResponse::success(['product' => ProductResource::make($product)]);
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
