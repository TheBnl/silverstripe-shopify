<?php

use GuzzleHttp\Client;

/**
 * Class ShopifyImage
 *
 * @property int Sort
 * @property string ShopifyID
 * @property string OriginalSrc
 *
 * @method ShopifyProduct Product
 */
class ShopifyImage extends Image
{
    private static $db = [
        'Sort' => 'Int',
        'ShopifyID' => 'Varchar(255)',
        'OriginalSrc' => 'Varchar(255)'
    ];

    private static $default_sort = 'Sort ASC';

    private static $data_map = [
        'id' => 'ShopifyID',
        'alt' => 'Title',
        'position' => 'Sort',
        'src' => 'OriginalSrc',
        'created_at' => 'Created',
        'updated_at' => 'LastEdited'
    ];

    private static $has_one = [
        'Product' => ShopifyProduct::class
    ];

    private static $has_many = [
        'Variants' => ShopifyProductVariant::class
    ];

    private static $indexes = [
        'ShopifyID' => true
    ];

    private static $summary_fields = [
        'CMSThumbnail' => 'Image',
        'Title',
        'ShopifyID'
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        return $fields;
    }

    /**
     * Creates a new Shopify Image from the given data
     *
     * @param $shopifyImage
     * @return Image
     * @throws ValidationException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public static function findOrMakeFromShopifyData($shopifyImage)
    {
        if (!$image = self::getByShopifyID($shopifyImage->id)) {
            $image = self::create();
        }

        $map = self::config()->get('data_map');
        ShopifyImport::loop_map($map, $image, $shopifyImage);

        // import the image if the source has changed
        if ($image->isChanged('OriginalSrc', DataObject::CHANGE_VALUE)) {
            $folder = isset($shopifyImage->product_id) ? $shopifyImage->product_id : 'collection';
            $image->downloadImage($image->OriginalSrc, "shopify/$folder");
        }

        if ($image->isChanged()) {
            $image->write();
        }

        return $image;
    }

    public static function getByShopifyID($shopifyId)
    {
        return DataObject::get_one(self::class, ['ShopifyID' => $shopifyId]);
    }

    /**
     * Download the image from the shopify CDN
     */
    private function downloadImage($src, $folder)
    {
        $folder = Folder::find_or_make($folder);
        $sourcePath = pathinfo($src);
        $fileName = explode('?', $sourcePath['basename'])[0];

        $baseFolder = Director::baseFolder();
        $relativeFilePath = $folder->Filename . $fileName;
        $absoluteFilePath = "$baseFolder/$relativeFilePath";

        $client = new Client(['http_errors' => false]);

        $resource = fopen($absoluteFilePath, 'w');
        $stream = GuzzleHttp\Psr7\stream_for($resource);
        $client->request('GET', $src, ['save_to' => $stream]);
        fclose($resource);

        $this->setField('ParentID', $folder->ID);
        $this->OwnerID = (Member::currentUser()) ? Member::currentUser()->ID : 0;
        $this->setName($fileName);
        $this->setFilename($relativeFilePath);
    }
}
