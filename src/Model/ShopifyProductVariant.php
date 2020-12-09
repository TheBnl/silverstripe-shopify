<?php

/**
 * Class ProductVariant
 *
 * @author Bram de Leeuw
 *
 * @property string Title
 * @property string ShopifyID
 * @property float Price
 * @property float CompareAtPrice
 * @property string SKU
 * @property int Sort
 * @property string Option1
 * @property string Option2
 * @property string Option3
 * @property boolean Taxable
 * @property string Barcode
 * @property int Inventory
 * @property int Grams
 * @property float Weight
 * @property string WeightUnit
 * @property int InventoryItemID
 * @property boolean RequiresShipping
 *
 * @property int ProductID
 * @method ShopifyProduct Product()
 * @property int ImageID
 * @method ShopifyImage Image()
 */
class ShopifyProductVariant extends DataObject
{
    private static $db = [
        'Title' => 'Varchar(255)',
        'ShopifyID' => 'Varchar(255)',
        'Price' => 'Currency',
        'CompareAtPrice' => 'Currency',
        'SKU' => 'Varchar(255)',
        'Sort' => 'Int',
        'Option1' => 'Varchar(255)',
        'Option2' => 'Varchar(255)',
        'Option3' => 'Varchar(255)',
        'Taxable' => 'Boolean',
        'Barcode' => 'Varchar(255)',
        'Inventory' => 'Int',
        'Grams' => 'Int',
        'Weight' => 'Decimal',
        'WeightUnit' => 'Varchar(255)',
        'InventoryItemID' => 'Varchar(255)',
        'RequiresShipping' => 'Boolean'
    ];

    private static $data_map = [
        'id'=> 'ShopifyID',
        'title'=> 'Title',
        'price'=> 'Price',
        'compare_at_price'=> 'CompareAtPrice',
        'sku'=> 'SKU',
        'position' => 'Sort',
        'option1' => 'Option1',
        'option2' => 'Option2',
        'option3' => 'Option3',
        'created_at' => 'Created',
        'updated_at' => 'LastEdited',
        'taxable' => 'Taxable',
        'barcode' => 'Barcode',
        'grams' => 'Grams',
        'inventory_quantity' => 'Inventory',
        'weight' => 'Weight',
        'weight_unit' => 'WeightUnit',
        'inventory_item_id' => 'InventoryItemID',
        'requires_shipping' => 'RequiresShipping'
    ];

    private static $has_one = [
        'Product' => ShopifyProduct::class,
        'Image' => ShopifyImage::class
    ];

    private static $indexes = [
        'ShopifyID' => true
    ];

    private static $summary_fields = [
        'Image.CMSThumbnail' => 'Image',
        'Title',
        'Price',
        'SKU',
        'ShopifyID'
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        return $fields;
    }

    /**
     * Creates a new Shopify Variant from the given data
     *
     * @param $shopifyVariant
     * @return ShopifyProductVariant
     * @throws ValidationException
     */
    public static function findOrMakeFromShopifyData($shopifyVariant)
    {
        if (!$variant = self::getByShopifyID($shopifyVariant->id)) {
            $variant = self::create();
        }

        $map = self::config()->get('data_map');
        ShopifyImport::loop_map($map, $variant, $shopifyVariant);

        if ($image = ShopifyImage::getByShopifyID($shopifyVariant->image_id)) {
            $variant->ImageID = $image->ID;
        }

        if ($variant->isChanged()) {
            $variant->write();
        }
        
        return $variant;
    }

    public static function getByShopifyID($shopifyId)
    {
        return DataObject::get_one(self::class, ['ShopifyID' => $shopifyId]);
    }

    public function canView($member = null)
    {
        return $this->Product()->canView($member);
    }

    public function canEdit($member = null)
    {
        return $this->Product()->canEdit($member);
    }

    public function canDelete($member = null)
    {
        return $this->Product()->canDelete($member);
    }

    public function canCreate($member = null)
    {
        return $this->Product()->canCreate($member);
    }
}
