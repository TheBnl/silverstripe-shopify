<?php

/**
 * Class ShopifyProductAdmin
 */
class ShopifyProductAdmin extends ModelAdmin
{
    private static $managed_models = [
        ShopifyCollection::class,
        ShopifyProduct::class
    ];

    private static $url_segment = 'shopify';

    private static $menu_title = 'Shopify';

    private static $menu_icon_class = 'font-icon-cart';
}
