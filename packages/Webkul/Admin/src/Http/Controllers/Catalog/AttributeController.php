<?php

namespace Webkul\Admin\Http\Controllers\Catalog;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Webkul\Admin\DataGrids\Catalog\AttributeDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Admin\Http\Requests\MassDestroyRequest;
use Webkul\Attribute\Enums\AttributeTypeEnum;
use Webkul\Attribute\Enums\SwatchTypeEnum;
use Webkul\Attribute\Enums\ValidationEnum;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Core\Rules\Code;
use Webkul\Product\Repositories\ProductRepository;

class AttributeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected AttributeRepository $attributeRepository,
        protected ProductRepository $productRepository
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return View
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(AttributeDataGrid::class)->process();
        }

        return view('admin::catalog.attributes.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return View
     */
    public function create()
    {
        $locales = core()->getAllLocales();

        $attributeTypes = AttributeTypeEnum::getValues();

        $swatchTypes = SwatchTypeEnum::getValues();

        $validations = ValidationEnum::getValues();

        return view('admin::catalog.attributes.create', compact('locales', 'attributeTypes', 'swatchTypes', 'validations'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store()
    {
        $rules = [
            'code' => ['required', 'not_in:type,attribute_family_id', 'unique:attributes,code', new Code],
            'admin_name' => 'required',
            'type' => 'required',
        ];

        if (request('type') === 'boolean') {
            $rules['default_value'] = 'in:0,1';
        }

        $this->validate(request(), $rules);

        $this->guardAgainstTruncatedOptionsPayload();

        $requestData = request()->all();

        $requestData['default_value'] ??= null;

        Event::dispatch('catalog.attribute.create.before');

        $attribute = $this->attributeRepository->create($requestData);

        Event::dispatch('catalog.attribute.create.after', $attribute);

        session()->flash('success', trans('admin::app.catalog.attributes.create-success'));

        return redirect()->route('admin.catalog.attributes.index');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return View
     */
    public function edit(int $id)
    {
        $attribute = $this->attributeRepository->findOrFail($id);

        $locales = core()->getAllLocales();

        $attributeTypes = AttributeTypeEnum::getValues();

        $swatchTypes = SwatchTypeEnum::getValues();

        $validations = ValidationEnum::getValues();

        return view('admin::catalog.attributes.edit', compact('attribute', 'locales', 'attributeTypes', 'swatchTypes', 'validations'));
    }

    /**
     * Get attribute options associated with attribute.
     *
     * @return View
     */
    public function getAttributeOptions(int $id)
    {
        $attribute = $this->attributeRepository->findOrFail($id);

        return $attribute->options()->orderBy('sort_order')->get();
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(int $id)
    {
        $rules = [
            'code' => ['required', 'unique:attributes,code,'.$id, new Code],
            'admin_name' => 'required',
            'type' => 'required',
        ];

        if (request('type') === 'boolean') {
            $rules['default_value'] = 'in:0,1';
        }

        $this->validate(request(), $rules);

        $this->guardAgainstTruncatedOptionsPayload();

        $requestData = request()->all();

        $requestData['default_value'] ??= null;

        Event::dispatch('catalog.attribute.update.before', $id);

        $attribute = $this->attributeRepository->update($requestData, $id);

        Event::dispatch('catalog.attribute.update.after', $attribute);

        session()->flash('success', trans('admin::app.catalog.attributes.update-success'));

        return redirect()->route('admin.catalog.attributes.index');
    }

    /**
     * Attributes with a large number of options (a "Brand" attribute with hundreds of
     * values is a common real-world case) render one or more hidden `<input>` fields
     * per option, per locale. Once the total number of individual form fields exceeds
     * PHP's `max_input_vars` directive (1000 by default), PHP silently drops the
     * remaining fields while parsing the request -- before Laravel, our validation
     * rules, or this controller ever see the data. The save then "succeeds" against a
     * truncated payload, silently discarding whichever options landed past the cutoff.
     *
     * We can't detect this from `$_POST` alone, since a genuinely small options list
     * looks identical to a truncated one. Instead, the options table view emits an
     * `options_count` hidden field early in the form (before the bulk of the
     * `options[...]` fields), so it reliably survives truncation and tells us how many
     * options the browser actually tried to send. If the number of options Laravel
     * received doesn't match, we know the request was truncated and fail loudly with
     * an actionable error instead of silently saving partial data.
     */
    protected function guardAgainstTruncatedOptionsPayload(): void
    {
        $expectedOptionsCount = request('options_count');

        if ($expectedOptionsCount === null || $expectedOptionsCount === '') {
            return;
        }

        $receivedOptionsCount = count(request('options', []));

        if ((int) $expectedOptionsCount <= $receivedOptionsCount) {
            return;
        }

        $maxInputVars = ini_get('max_input_vars');

        throw ValidationException::withMessages([
            'options' => trans('admin::app.catalog.attributes.options-truncated-error', [
                'expected' => $expectedOptionsCount,
                'received' => $receivedOptionsCount,
                'max_input_vars' => $maxInputVars,
            ]),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $attribute = $this->attributeRepository->findOrFail($id);

        if (! $attribute->is_user_defined) {
            return response()->json([
                'message' => trans('admin::app.catalog.attributes.user-define-error'),
            ], 400);
        }

        try {
            Event::dispatch('catalog.attribute.delete.before', $id);

            $this->attributeRepository->delete($id);

            Event::dispatch('catalog.attribute.delete.after', $id);

            return new JsonResponse([
                'message' => trans('admin::app.catalog.attributes.delete-success'),
            ]);
        } catch (\Exception $e) {
        }

        return new JsonResponse([
            'message' => trans('admin::app.catalog.attributes.delete-failed'),
        ], 500);
    }

    /**
     * Remove the specified resources from database.
     */
    public function massDestroy(MassDestroyRequest $massDestroyRequest): JsonResponse
    {
        $indices = $massDestroyRequest->input('indices');

        foreach ($indices as $index) {
            $attribute = $this->attributeRepository->find($index);

            if (! $attribute->is_user_defined) {
                return response()->json([
                    'message' => trans('admin::app.catalog.attributes.delete-failed'),
                ], 422);
            }
        }

        try {
            foreach ($indices as $index) {
                Event::dispatch('catalog.attribute.delete.before', $index);

                $this->attributeRepository->delete($index);

                Event::dispatch('catalog.attribute.delete.after', $index);
            }

            return new JsonResponse([
                'message' => trans('admin::app.catalog.attributes.index.datagrid.mass-delete-success'),
            ]);
        } catch (\Exception $exception) {
            return new JsonResponse([
                'message' => trans('admin::app.catalog.attributes.delete-failed'),
            ], 500);
        }
    }

    /**
     * Get super attributes of product.
     *
     * @return JsonResponse
     */
    public function productSuperAttributes(int $id)
    {
        $product = $this->productRepository->findOrFail($id);

        $superAttributes = $this->productRepository->getSuperAttributes($product);

        return response()->json([
            'data' => $superAttributes,
        ]);
    }
}
