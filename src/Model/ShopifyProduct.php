<?php

/**
 * Class Product
 *
 * @author Bram de Leeuw
 * @package XD\Shopify
 * @subpackage Model
 *
 * @mixin Versioned
 *
 * @property string Title
 * @property string URLSegment
 * @property string ShopifyID
 * @property string Content
 * @property string Vendor
 * @property string ProductType
 * @property string Tags
 *
 * @property int ImageID
 * @method ShopifyImage Image()
 *
 * @method HasManyList Variants()
 * @method HasManyList Images()
 */
class ShopifyProduct extends DataObject
{
    private static $currency = 'EUR';

    private static $options = [
        'product' => [
            'contents' => [
                'title' => false,
                'variantTitle' => false,
                'price' => false,
                'description' => false,
                'quantity' => false,
                'img' => false,
            ]
        ]
    ];

    private static $db = [
        'Title' => 'Varchar(255)',
        'URLSegment' => 'Varchar(255)',
        'ShopifyID' => 'Varchar(255)',
        'Content' => 'HTMLText',
        'Vendor' => 'Varchar(255)',
        'ProductType' => 'Varchar(255)',
        'Tags' => 'Varchar(255)'
    ];

    private static $default_sort = 'Created DESC';

    private static $searchable_fields = [
        'Title',
        'URLSegment',
        'ShopifyID',
        'Content',
        'Vendor',
        'ProductType',
        'Tags'
    ];

    private static $data_map = [
        'id' => 'ShopifyID',
        'title' => 'Title',
        'body_html' => 'Content',
        'vendor' => 'Vendor',
        'product_type' => 'ProductType',
        'created_at' => 'Created',
        'handle' => 'URLSegment',
        'updated_at' => 'LastEdited',
        'tags' => 'Tags',
    ];

    private static $has_one = [
        'Image' => ShopifyImage::class
    ];

    private static $has_many = [
        'Variants' => ShopifyProductVariant::class,
        'Images' => ShopifyImage::class
    ];

    private static $belongs_many_many = [
        'Collections' => ShopifyCollection::class
    ];

    private static $owns = [
        'Variants',
        'Images',
        'Image'
    ];

    private static $indexes = [
        'ShopifyID' => true,
        'URLSegment' => true
    ];

    private static $summary_fields = [
        'Image.CMSThumbnail' => 'Image',
        'Title',
        'Vendor',
        'ProductType',
        'ShopifyID'
    ];

    private static $casting = array(
        'MetaTags' => 'HTMLFragment'
    );

    private static $extensions = [
        Versioned::class
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->addFieldsToTab('Root.Main', [
            ReadonlyField::create('Title'),
            ReadonlyField::create('URLSegment'),
            ReadonlyField::create('ShopifyID'),
            ReadonlyField::create('Content'),
            ReadonlyField::create('Vendor'),
            ReadonlyField::create('ProductType'),
            ReadonlyField::create('Tags'),
            UploadField::create('Image')->performReadonlyTransformation(),
        ]);

        $fields->addFieldsToTab('Root.Variants', [
            GridField::create('Variants', 'Variants', $this->Variants(), GridFieldConfig_RecordViewer::create())
        ]);

        $fields->addFieldsToTab('Root.Images', [
            GridField::create('Images', 'Images', $this->Images(), GridFieldConfig_RecordViewer::create())
        ]);

        $fields->removeByName(['LinkTracking','FileTracking']);
        return $fields;
    }

    public function getVariantWithLowestPrice()
    {
        return DataObject::get_one(ShopifyProductVariant::class, ['ProductID' => $this->ID], true, 'Price ASC');
    }

    /**
     * @return Currency|null
     */
    public function getPrice()
    {
        if ($product = $this->getVariantWithLowestPrice()) {
            return $product->dbObject('Price');
        }

        return null;
    }

    /**
     * @return Currency|null
     */
    public function getCompareAtPrice()
    {
        if ($product = $this->getVariantWithLowestPrice()) {
            return $product->dbObject('CompareAtPrice');
        }

        return null;
    }

    /**
     * Merge in the configured button options
     *
     * @return string
     */
    public function getButtonOptions()
    {
        return Convert::array2json(array_merge_recursive(self::config()->get('options'), [
            'product' => [
                'text' => [
                    'button' => _t('Shopify.ProductButton', 'Add to cart'),
                    'outOfStock' => _t('Shopify.ProductOutOfStock', 'Out of stock'),
                    'unavailable' => _t('Shopify.ProductUnavailable', 'Unavailable'),
                ]
            ]
        ]));
    }

    public function getButtonScript()
    {
        if ($this->ShopifyID) {
            $currencySymbol = Currency::config()->get('currency_symbol');
            Requirements::customScript(<<<JS
            (function () {
                if (window.shopifyClient) {
                    window.shopifyClient.createComponent('product', {
                        id: {$this->ShopifyID},
                        node: document.getElementById('product-component-{$this->ShopifyID}'),
                        moneyFormat: '$currencySymbol{{amount}}',
                        options: {$this->ButtonOptions}
                    });
                }
            })();
JS
            );
        }
    }
    
    public function Link($action = null)
    {
        $shopifyPage = ShopifyPage::inst();
        return Controller::join_links($shopifyPage->Link('product'), $this->URLSegment, $action);
    }

    public function AbsoluteLink($action = null) {
        return Director::absoluteURL($this->Link($action));
    }

    public function MetaTags($includeTitle = true)
    {
        $tags = '';
        $this->extend('MetaTags', $tags);
        return $tags;
    }

    public function getOGImage()
    {
        if (($image = $this->Image()) && $image->exists()) {
            return $image->Pad(1200, 630)->getAbsoluteURL();
        }

        return null;
    }

    /**
     * Creates a new Shopify Product from the given data
     * but does not publish it
     *
     * @param $shopifyProduct
     * @return Product
     * @throws ValidationException
     */
    public static function findOrMakeFromShopifyData($shopifyProduct)
    {
        if (!$product = self::getByShopifyID($shopifyProduct->id)) {
            $product = self::create();
        }

        $map = self::config()->get('data_map');
        ShopifyImport::loop_map($map, $product, $shopifyProduct);

        if ($product->isChanged()) {
            $product->write();
        }
        
        return $product;
    }

    public static function getByShopifyID($shopifyId)
    {
        return DataObject::get_one(self::class, ['ShopifyID' => $shopifyId]);
    }

    public static function getByURLSegment($urlSegment)
    {
        return DataObject::get_one(self::class, ['URLSegment' => $urlSegment]);
    }

    function canView($member = null) {
        return true;
    }

    function canpublish()
    {
        return $this->canEdit();
    }
}
