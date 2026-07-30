<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Enums\AttributeTypeEnum;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Helpers\CatalogApiCache;
use Webkul\Shop\Http\Resources\AttributeOptionResource;
use Webkul\Shop\Http\Resources\AttributeResource;
use Webkul\Shop\Http\Resources\CategoryResource;
use Webkul\Shop\Http\Resources\CategoryTreeResource;

class CategoryController extends APIController
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected AttributeRepository $attributeRepository,
        protected CategoryRepository $categoryRepository,
        protected ProductRepository $productRepository,
        protected CatalogApiCache $catalogApiCache
    ) {}

    /**
     * Get all categories.
     */
    public function index(): JsonResponse
    {
        /**
         * These are the default parameters. By default, only the enabled category
         * will be shown in the current locale.
         */
        $defaultParams = [
            'status' => 1,
            'locale' => app()->getLocale(),
        ];

        /**
         * Category listings are cached per catalog version, so repeated
         * storefront visits skip the database entirely.
         */
        $data = $this->catalogApiCache->remember('categories', request()->all(), function () use ($defaultParams) {
            $categories = $this->categoryRepository->getAll(array_merge($defaultParams, request()->all()));

            return CategoryResource::collection($categories)
                ->response()
                ->getData(true);
        });

        return response()->json($data)->withHeaders($this->catalogCacheHeaders());
    }

    /**
     * Get all categories in tree format.
     */
    public function tree(): JsonResource
    {
        $categories = $this->categoryRepository->getVisibleCategoryTree(core()->getCurrentChannel()->root_category_id);

        return CategoryTreeResource::collection($categories);
    }

    /**
     * Get filterable attributes for category.
     */
    public function getAttributes(): JsonResource
    {
        if (! request('category_id')) {
            $filterableAttributes = $this->attributeRepository->getFilterableAttributes();

            return AttributeResource::collection($filterableAttributes);
        }

        $category = $this->categoryRepository->findOrFail(request('category_id'));

        if (empty($filterableAttributes = $category->filterableAttributes)) {
            $filterableAttributes = $this->attributeRepository->getFilterableAttributes();
        }

        return AttributeResource::collection($filterableAttributes);
    }

    /**
     * Get attribute options with pagination and search.
     */
    public function getAttributeOptions(int $attributeId): mixed
    {
        $attribute = $this->attributeRepository->findOrFail($attributeId);

        if ($attribute->type === AttributeTypeEnum::BOOLEAN->value) {
            return new JsonResponse([
                'data' => AttributeTypeEnum::getBooleanOptions(),
            ]);
        }

        $query = $attribute->options()
            ->with([
                'translation' => fn ($query) => $query->where('locale', core()->getCurrentLocale()->code),
            ]);

        if ($search = request('search')) {
            $query->where(function ($query) use ($search) {
                $query->whereHas('translation', fn ($query) => $query->where('label', 'like', "%{$search}%"))
                    ->orWhere('admin_name', 'like', "%{$search}%");
            });
        }

        $this->scopeOptionsToProductsInCategory($query, $attribute, request('category_id'));

        $query->orderBy('sort_order');

        return AttributeOptionResource::collection($query->paginate());
    }

    /**
     * Restrict a filterable attribute's options to only the ones actually
     * assigned to at least one product within the given category.
     *
     * Without this, a filter such as "Brand" lists every brand that has ever
     * been created store-wide, even when browsing a category whose products
     * only use two or three of them -- customers can select a brand filter
     * that is guaranteed to return zero results.
     *
     * No-op when the attribute isn't option-based (select/multiselect) or
     * when no category is given, so this keeps the previous global-options
     * behavior for the "shop all attributes" listing (`getAttributes()`
     * without a `category_id`) and for the price-range and other non-option
     * filters.
     */
    protected function scopeOptionsToProductsInCategory(Builder $query, Attribute $attribute, $categoryId): void
    {
        if (! $categoryId) {
            return;
        }

        if (! in_array($attribute->type, [
            AttributeTypeEnum::SELECT->value,
            AttributeTypeEnum::MULTISELECT->value,
        ])) {
            return;
        }

        $isMultiselect = $attribute->type === AttributeTypeEnum::MULTISELECT->value;

        $query->whereExists(function ($subQuery) use ($attribute, $categoryId, $isMultiselect) {
            $subQuery->select(DB::raw(1))
                ->from('product_attribute_values')
                ->join('product_categories', 'product_categories.product_id', '=', 'product_attribute_values.product_id')
                ->where('product_attribute_values.attribute_id', $attribute->id)
                ->where('product_categories.category_id', $categoryId)
                ->when(
                    $isMultiselect,
                    fn ($query) => $query->whereRaw('find_in_set(attribute_options.id, product_attribute_values.text_value)'),
                    fn ($query) => $query->whereColumn('product_attribute_values.integer_value', 'attribute_options.id')
                );
        });
    }

    /**
     * Get product maximum price.
     */
    public function getProductMaxPrice($categoryId = null): JsonResource
    {
        if (core()->getConfigData('catalog.products.search.engine') == 'elastic') {
            $searchEngine = core()->getConfigData('catalog.products.search.storefront_mode');
        }

        $maxPrice = $this->productRepository
            ->setSearchEngine($searchEngine ?? 'database')
            ->getMaxPrice([
                'category_id' => $categoryId,
                'attribute_code' => request('attribute_code', 'price'),
            ]);

        return new JsonResource([
            'max_price' => core()->convertPrice($maxPrice),
        ]);
    }
}
