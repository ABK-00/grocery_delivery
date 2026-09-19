<?php

/**
 * Escape output safely.
 */
function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/**
 * Redirect to another page.
 */
function redirect($url)
{
    header("Location: " . $url);
    exit;
}


/**
 * Return a usable product image URL.
 *
 * $depth:
 * 0 = page is in project root
 * 1 = page is inside admin/, customer/, staff/, etc.
 */
function productImage($image = null, $depth = 0)
{
    $prefix = $depth > 0
        ? str_repeat('../', $depth)
        : '';

    $placeholder =
        $prefix . 'assets/images/product-placeholder.svg';

    if (
        $image === null ||
        trim((string)$image) === ''
    ) {
        return $placeholder;
    }

    $image = trim((string)$image);


    /*
    |--------------------------------------------------------------------------
    | External image
    |--------------------------------------------------------------------------
    */

    if (
        str_starts_with($image, 'http://') ||
        str_starts_with($image, 'https://')
    ) {
        return $image;
    }


    /*
    |--------------------------------------------------------------------------
    | Clean stored path
    |--------------------------------------------------------------------------
    */

    $image = str_replace('\\', '/', $image);

    $image = ltrim($image, '/');


    /*
    |--------------------------------------------------------------------------
    | Database already stores uploads/...
    |--------------------------------------------------------------------------
    */

    if (str_starts_with($image, 'uploads/')) {
        return $prefix . $image;
    }


    /*
    |--------------------------------------------------------------------------
    | Database stores products/...
    |--------------------------------------------------------------------------
    */

    if (str_starts_with($image, 'products/')) {
        return $prefix . 'uploads/' . $image;
    }


    /*
    |--------------------------------------------------------------------------
    | Database stores only filename
    |--------------------------------------------------------------------------
    */

    return $prefix . 'uploads/products/' . $image;
}


/**
 * Pick the best image available for a product.
 */
function getProductImage(array $product, $depth = 0)
{
    $image =
        $product['primary_image']
        ?? $product['image']
        ?? null;

    return productImage($image, $depth);
}


/**
 * Currency formatter.
 */
function money($amount)
{
    return 'GH₵ ' . number_format(
        (float)$amount,
        2
    );
}