<?php

/**
 * Class Collection
 *
 * @author Bram de Leeuw
 *
 * @mixin Versioned
 *
 * @property string Title
 * @property string URLSegment
 * @property string ShopifyID
 * @property string Content
 *
 * @property int ImageID
 * @method Image Image()
 *
 * @method ManyManyList Products()
 */
class ShopifyCollection extends DataObject
{
    private static $db = [
        'Title' => 'Varchar(255)',
        'URLSegment' => 'Varchar(255)',
        'ShopifyID' => 'Varchar(255)',
        'Content' => 'HTMLText'
    ];

    private static $data_map = [
        'id' => 'ShopifyID',
        'handle' => 'URLSegment',
        'title' => 'Title',
        'body_html' => 'Content',
        'updated_at' => 'LastEdited',
        'created_at' => 'Created',
    ];

    private static $has_one = [
        'Image' => ShopifyImage::class
    ];

    private static $many_many = [
        'Products' => ShopifyProduct::class,
    ];

    private static $many_many_extraFields = [
        'Products' => [
            'SortValue' => 'Varchar(255)',
            'Position' => 'Int',
            'Featured' => 'Boolean',
            'Imported' => 'Boolean'
        ],
    ];

    private static $owns = [
        'Image'
    ];

    private static $indexes = [
        'ShopifyID' => true,
        'URLSegment' => true
    ];

    private static $summary_fields = [
        'Image.CMSThumbnail' => 'Image',
        'Title',
        'ShopifyID'
    ];

    private static $searchable_fields = [
        'Title',
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
        $self =& $this;
        $this->beforeUpdateCMSFields(function (FieldList $fields) use ($self) {
            $fields->addFieldsToTab('Root.Main', [
                ReadonlyField::create('Title'),
                ReadonlyField::create('URLSegment'),
                ReadonlyField::create('ShopifyID'),
                ReadonlyField::create('Content'),
                UploadField::create('Image')->performReadonlyTransformation(),
            ]);

            $fields->addFieldsToTab('Root.Products', [
                GridField::create('Products', 'Products', $this->Products(), GridFieldConfig_RecordViewer::create())
            ]);
        });
        
        $fields = parent::getCMSFields();
        $fields->removeByName(['LinkTracking', 'FileTracking']);
        return $fields;
    }

    public function Link($action = null)
    {
        $shopifyPage = ShopifyPage::inst();
        return Controller::join_links($shopifyPage->Link('collection'), $this->URLSegment, $action);
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
     * Creates a new Shopify Collection from the given data
     * but does not publish it
     *
     * @param $shopifyCollection
     * @return ShopifyCollection
     * @throws ValidationException
     */
    public static function findOrMakeFromShopifyData($shopifyCollection)
    {
        if (!$collection = self::getByShopifyID($shopifyCollection->id)) {
            $collection = self::create();
        }

        $map = self::config()->get('data_map');
        ShopifyImport::loop_map($map, $collection, $shopifyCollection);

        if ($collection->isChanged()) {
            $collection->write();
        }
        
        return $collection;
    }

    /**
     * @param $shopifyId
     *
     * @return DataObject|ShopifyCollection
     */
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
}
