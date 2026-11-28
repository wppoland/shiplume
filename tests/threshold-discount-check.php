<?php

/**
 * The cart total the bar measures must follow the free-shipping method's
 * "Apply minimum order rule before coupon discount" box, as WooCommerce does.
 *
 * cartTotal() always subtracted discounts. With the box ticked, a 120 cart
 * with a 20% coupon gets free shipping from WooCommerce while the bar still
 * said "Add 4.00 more".
 *
 * Also: a non-core method counts through the nudge/method_free_amount filter.
 *
 * Run: php tests/threshold-discount-check.php
 */

declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__);

    class WC_Shipping_Method
    {
        /** @param array<string, string> $options */
        public function __construct(private array $options, public string $id = 'free_shipping', private bool $enabled = true)
        {
        }

        public function is_enabled(): bool
        {
            return $this->enabled;
        }

        public function get_option(string $key): string
        {
            return $this->options[$key] ?? '';
        }
    }

    class WC_Shipping_Zone
    {
        /** @return list<WC_Shipping_Method> */
        public function get_shipping_methods(bool $enabledOnly): array
        {
            return WC_Shipping_Zones::$methods;
        }
    }

    class WC_Shipping_Zones
    {
        /** @var list<WC_Shipping_Method> */
        public static array $methods = [];

        /** @return list<array<string, mixed>> */
        public static function get_zones(): array
        {
            return [['shipping_methods' => self::$methods]];
        }

        public static function get_zone_by(string $by, int $id): ?WC_Shipping_Zone
        {
            return null;
        }

        public static function get_zone(int $id): WC_Shipping_Zone
        {
            return new WC_Shipping_Zone();
        }
    }

    class WC_Cart
    {
        public function get_displayed_subtotal(): float
        {
            return 120.0;
        }

        public function display_prices_including_tax(): bool
        {
            return false;
        }

        public function get_discount_tax(): float
        {
            return 0.0;
        }

        public function get_discount_total(): float
        {
            return 24.0;
        }
    }

    function WC(): object
    {
        return (object) ['cart' => new WC_Cart()];
    }

    /** @var array<string, callable> */
    $GLOBALS['filters'] = [];

    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return isset($GLOBALS['filters'][$hook]) ? $GLOBALS['filters'][$hook]($value, ...$args) : $value;
    }

    function wc_format_decimal(string $value): string
    {
        return $value;
    }

    function wc_get_price_decimals(): int
    {
        return 2;
    }

    require __DIR__ . '/../src/Service/ThresholdResolver.php';

    $failures = 0;
    foreach (['no' => 96.0, 'yes' => 120.0] as $ignore => $want) {
        WC_Shipping_Zones::$methods = [new WC_Shipping_Method(['requires' => 'min_amount', 'min_amount' => '100', 'ignore_discounts' => $ignore])];
        $resolver  = new \Nudge\Service\ThresholdResolver();
        $threshold = $resolver->threshold(['threshold_source' => 'auto']);
        $total     = $resolver->cartTotal();
        if (100.0 !== $threshold || $want !== $total) {
            echo "FAIL: ignore_discounts={$ignore} gave threshold {$threshold}, total {$total}; expected 100, {$want}\n";
            $failures++;
        }
    }

    WC_Shipping_Zones::$methods = [];
    $none = (new \Nudge\Service\ThresholdResolver())->threshold(['threshold_source' => 'auto'] + (require __DIR__ . '/../config/defaults.php'));
    if (0.0 !== $none) {
        echo "FAIL: with no free-shipping method and default settings the threshold is {$none}, expected 0 (no bar)\n";
        $failures++;
    }

    // A non-core method counts only through nudge/method_free_amount, and only while enabled.
    $core    = new WC_Shipping_Method(['requires' => 'min_amount', 'min_amount' => '100', 'ignore_discounts' => 'yes']);
    $carrier = new WC_Shipping_Method(['free_above' => '80'], 'carrier');
    $off     = new WC_Shipping_Method(['free_above' => '50'], 'carrier', false);
    $GLOBALS['filters']['nudge/method_free_amount'] = static fn (float $amount, WC_Shipping_Method $m): float => 'carrier' === $m->id ? (float) $m->get_option('free_above') : $amount;
    $cases = [
        'no filter amount: core only'  => [[$core, new WC_Shipping_Method([], 'flat_rate')], 100.0, 120.0],
        'carrier lower: carrier wins'  => [[$core, $carrier, $off], 80.0, 96.0],
        'carrier alone'                => [[$carrier], 80.0, 96.0],
        'disabled carrier ignored'     => [[$off], 0.0, 96.0],
    ];
    foreach ($cases as $name => [$methods, $want, $wantTotal]) {
        WC_Shipping_Zones::$methods = $methods;
        $resolver = new \Nudge\Service\ThresholdResolver();
        $got      = $resolver->threshold(['threshold_source' => 'auto', 'manual_threshold' => 0]);
        $total    = $resolver->cartTotal();
        $zone     = (new \Nudge\Service\ThresholdResolver())->zoneThreshold(3);
        if ($want !== $got || $want !== $zone || $wantTotal !== $total) {
            echo "FAIL: {$name}: threshold {$got}, zone {$zone}, total {$total}; expected {$want}, {$wantTotal}\n";
            $failures++;
        }
    }

    echo 0 === $failures ? "OK: progress follows the free-shipping discount rule\n" : '';
    exit($failures > 0 ? 1 : 0);
}
