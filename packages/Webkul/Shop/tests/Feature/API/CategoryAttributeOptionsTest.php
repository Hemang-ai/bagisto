<?php

use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductAttributeValue;

use function Pest\Laravel\getJson;

it('only returns brand options that have a product in the requested category', function () {
    // Arrange: a "brand" select attribute with three options, but only two
    // of them are actually assigned to a product in "Category A".
    $brandAttribute = Attribute::factory()->create([
        'code' => 'brand',
        'type' => 'select',
        'is_filterable' => true,
    ]);

    $optionA = AttributeOption::factory()->create(['attribute_id' => $brandAttribute->id]);
    $optionB = AttributeOption::factory()->create(['attribute_id' => $brandAttribute->id]);
    $optionUnused = AttributeOption::factory()->create(['attribute_id' => $brandAttribute->id]);

    $categoryA = Category::factory()->create();
    $categoryB = Category::factory()->create();

    $productInA = Product::factory()->simple()->create();
    $productInA->categories()->attach($categoryA);

    ProductAttributeValue::factory()->create([
        'product_id' => $productInA->id,
        'attribute_id' => $brandAttribute->id,
        'integer_value' => $optionA->id,
    ]);

    $secondProductInA = Product::factory()->simple()->create();
    $secondProductInA->categories()->attach($categoryA);

    ProductAttributeValue::factory()->create([
        'product_id' => $secondProductInA->id,
        'attribute_id' => $brandAttribute->id,
        'integer_value' => $optionB->id,
    ]);

    // $optionUnused only has a product in Category B, not Category A.
    $productInB = Product::factory()->simple()->create();
    $productInB->categories()->attach($categoryB);

    ProductAttributeValue::factory()->create([
        'product_id' => $productInB->id,
        'attribute_id' => $brandAttribute->id,
        'integer_value' => $optionUnused->id,
    ]);

    // Act and Assert: requesting options scoped to Category A should return
    // only optionA and optionB, never optionUnused.
    $response = getJson(route('shop.api.categories.attribute_options', $brandAttribute->id).'?category_id='.$categoryA->id)
        ->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();

    expect($returnedIds)->toContain($optionA->id);
    expect($returnedIds)->toContain($optionB->id);
    expect($returnedIds)->not->toContain($optionUnused->id);
});

it('returns every option when no category_id is given, preserving prior behavior', function () {
    // Arrange.
    $brandAttribute = Attribute::factory()->create([
        'code' => 'brand',
        'type' => 'select',
        'is_filterable' => true,
    ]);

    $option = AttributeOption::factory()->create(['attribute_id' => $brandAttribute->id]);

    // No product/category is ever associated with this option.

    // Act and Assert: without a category_id, the endpoint should behave
    // exactly as it did before this change -- it lists every option
    // regardless of product assignment.
    $response = getJson(route('shop.api.categories.attribute_options', $brandAttribute->id))
        ->assertOk();

    $returnedIds = collect($response->json('data'))->pluck('id')->all();

    expect($returnedIds)->toContain($option->id);
});
