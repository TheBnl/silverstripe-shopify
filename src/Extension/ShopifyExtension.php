<?php

/**
 * Class ShopifyExtension
 *
 * @author Bram de Leeuw
 *
 * @property ShopifyExtension|Page_Controller $owner
 */
class ShopifyExtension extends Extension
{
    public function getCartOptions()
    {
        return Convert::array2json(array_merge_recursive(ShopifyProduct::config()->get('options'), [
            'cart' => [
                'text' => [
                    'title' => _t('Shopify.CartTitle', 'Cart'),
                    'empty'=> _t('Shopify.CartEmpty', 'Your cart is empty.'),
                    'button' => _t('Shopify.CartButton', 'Checkout'),
                    'total' => _t('Shopify.CartTotal', 'Subtotal'),
                    'currency' => ShopifyProduct::config()->get('currency'),
                    'notice' => _t('Shopify.CartNotice', 'Shipping and discount codes are added at checkout.')
                ]
            ]
        ]));
    }

    public function onAfterInit()
    {
        if (ShopifyClient::config()->get('inject_javascript') !== false) {
            $domain = ShopifyClient::config()->get('shopify_domain');
            $accessToken = ShopifyClient::config()->get('storefront_access_token');
            $currencySymbol = Currency::config()->get('currency_symbol');
            Requirements::javascript('//sdks.shopifycdn.com/buy-button/latest/buybutton.js');
            Requirements::customScript(<<<JS
            (function () {
                var client = ShopifyBuy.buildClient({
                  domain: '{$domain}',
                  storefrontAccessToken: '{$accessToken}'
                });
                
                window.shopifyClient = ShopifyBuy.UI.init(client);
                window.shopifyClient.createComponent('cart', {
                   node: document.getElementById('shopify-cart'),
                   moneyFormat: '$currencySymbol{{amount}}',
                   options: {$this->getCartOptions()}
                });
            })();
JS
            );
        }
    }
}
