<?php
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect($url){ header("Location: $url"); exit; }

/**
 * Resolve a product image stored as a filename, products/... or uploads/...
 * $depth is the number of directories below the project root.
 */
function productImage($image = null, $depth = 0)
{
    $prefix = $depth > 0 ? str_repeat('../', $depth) : '';
    $placeholder = $prefix . 'assets/images/product-placeholder.svg';
    if ($image === null || trim((string)$image) === '') return $placeholder;
    $image = trim(str_replace('\\', '/', (string)$image));
    if (preg_match('#^https?://#i', $image)) return $image;
    $image = ltrim($image, '/');
    if (str_starts_with($image, 'uploads/')) return $prefix . $image;
    if (str_starts_with($image, 'products/')) return $prefix . 'uploads/' . $image;
    return $prefix . 'uploads/products/' . $image;
}

function getProductImage(array $product, $depth = 0)
{
    $image = $product['primary_image'] ?? $product['image'] ?? null;
    return productImage($image, $depth);
}

function money($amount){ return 'GH₵ ' . number_format((float)$amount, 2); }
