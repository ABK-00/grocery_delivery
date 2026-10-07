<?php
function storefrontSlug(string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'store';
}
function uniqueStorefrontSlug(PDO $conn, string $name): string {
    $base = storefrontSlug($name); $slug=$base; $i=2;
    $q=$conn->prepare('SELECT id FROM companies WHERE storefront_slug=? LIMIT 1');
    while (true) { $q->execute([$slug]); if (!$q->fetchColumn()) return $slug; $slug=$base.'-'.$i++; }
}
function companyHasAccess(array $c): bool {
    if (($c['status'] ?? '') !== 'active') return false;
    $until = ($c['subscription_plan'] ?? '') === 'trial' ? ($c['trial_ends_at'] ?? null) : ($c['subscription_ends_at'] ?? null);
    return !$until || strtotime($until) >= time();
}
