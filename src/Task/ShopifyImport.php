<?php

/**
 * Class ShopifyImport
 *
 * @author Bram de Leeuw
 */
class ShopifyImport extends BuildTask
{
    const NOTICE = 0;
    const SUCCESS = 1;
    const WARN = 2;
    const ERROR = 3;

    protected $title = 'Shopify import products';

    protected $description = 'Import shopify products from the configured store';

    protected $enabled = true;

    public function run($request)
    {
        if (!Director::is_cli()) echo "<pre>";

        try {
            $client = new ShopifyClient();
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            exit($e->getMessage());
        } catch (Exception $e) {
            exit($e->getMessage());
        }

        $this->importCollections($client);
        // Import products listed to our app or import all products
        // Import listings need a special authentication so fallback to everything
        $importedListingIds = $this->getProductListingIds($client);
        $this->importProducts($client, $importedListingIds);
        $this->beforeImportCollects();
        $this->importCollects($client);
        $this->afterImportCollects();

        if (!Director::is_cli()) echo "</pre>";
        exit('Done');
    }

    /**
     * Get an array of available product ids
     *
     * @param ShopifyClient $client
     * @return array
     */
    public function getProductListingIds(ShopifyClient $client)
    {
        try {
            $listings = $client->productListingIds([
                'query' => [
                    'limit' => 250
                ]
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            exit($e->getMessage());
        }

        if (($listings = $listings->getBody()->getContents()) && $listings = Convert::json2obj($listings)) {
            return $listings->product_ids;
        }

        return [];
    }

    /**
     * Import the shopify products
     * @param ShopifyClient $client
     * @param array $ids
     *
     * @throws Exception
     */
    public function importProducts(ShopifyClient $client, array $ids = [], $sinceId = 0)
    {
        $query = [
            'limit' => 250,
            'published_scope' => 'global',
            'since_id' => $sinceId
        ];

        // if we have a list of id's use it as a filter
        if (count($ids)) {
            $query['ids'] = implode(',', $ids);
        }

        try {
            $products = $client->products([
                'query' => $query
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            exit($e->getMessage());
        }

        if (($products = $products->getBody()->getContents()) && $products = Convert::json2obj($products)) {
            $lastId = $sinceId;
            foreach ($products->products as $shopifyProduct) {
                // Create the product
                if ($product = $this->importObject(ShopifyProduct::class, $shopifyProduct)) {
                    // Create the images
                    $images = new ArrayList($shopifyProduct->images);
                    if ($images->exists()) {
                        foreach ($shopifyProduct->images as $shopifyImage) {
                            if ($image = $this->importObject(ShopifyImage::class, $shopifyImage)) {
                                $product->Images()->add($image);
                            }
                        }

                        // Cleanup old images
                        $current = $product->Images()->column('ShopifyID');
                        $new = $images->column('id');
                        $delete = array_diff($current, $new);
                        foreach ($delete as $shopifyId) {
                            if ($image = ShopifyImage::getByShopifyID($shopifyId)) {
                                $image->delete();
                                self::log("[$shopifyId] Deleted image", self::SUCCESS);
                            }
                        }
                    }

                    // attach the featured image
                    if (($image = $shopifyProduct->image) && ($imageID = $image->id) && ($image = ShopifyImage::getByShopifyID($imageID))) {
                        try {
                            $product->ImageID = $image->ID;
                        } catch (Exception $e) {
                            self::log($e->getMessage(), self::ERROR);
                        }
                    }

                    // Create the variants
                    if (!empty($shopifyProduct->variants)) {
                        $keepVariants = [];
                        foreach ($shopifyProduct->variants as $shopifyVariant) {
                            if ($variant = $this->importObject(ShopifyProductVariant::class, $shopifyVariant)) {
                                $keepVariants[] = $variant->ID;
                                $product->Variants()->add($variant);
                                self::log("[{$variant->ID}] Added variant {$product->Title}", self::SUCCESS);
                            }
                        }

                        foreach ($product->Variants()->exclude(['ID' => $keepVariants]) as $variant) {
                            /** @var ShopifyProductVariant $variant */
                            $variantId = $variant->ID;
                            $variantShopifyId = $variant->ShopifyID;
                            $variant->delete();
                            self::log("[{$variantId}][{$variantShopifyId}] Deleted old variant connected to product", self::SUCCESS);
                        }
                    }
                    if ($product->isChanged()) {
                        $product->write();
                        self::log("[{$product->ID}] Saved changes in product {$product->Title}", self::SUCCESS);
                    } else {
                        self::log("[{$product->ID}] Product {$product->Title} has no changes", self::SUCCESS);
                    }

                    // Publish the product and it's connections
                    if (!$product->latestPublished()) {
                        $product->publish('Stage', 'Live', false);
                        self::log("[{$product->ID}] Published product {$product->Title}", self::SUCCESS);
                    } else {
                        self::log("[{$product->ID}] Product {$product->Title} is alreaddy published", self::SUCCESS);
                    }

                    $product->extend('onAfterImport');
                    $lastId = $product->ShopifyID;
                } else {
                    self::log("[{$shopifyProduct->id}] Could not create product", self::ERROR);
                }
            }

            // todo make pagination work with products
            //if ($lastId !== $sinceId) {
            //    self::log("[{$sinceId}] Try to import the next page of products since last id", self::SUCCESS);
            //    $this->importProducts($client, $ids, $lastId);
            //}

            // Cleanup old products
            $newProducts = new ArrayList($products->products);
            $current = ShopifyProduct::get()->column('ShopifyID');
            $new = $newProducts->column('id');
            $delete = array_diff($current, $new);
            foreach ($delete as $shopifyId) {
                /** @var ShopifyProduct $product */
                if ($product = ShopifyProduct::getByShopifyID($shopifyId)) {
                    foreach ($product->Images() as $image) {
                        /** @var ShopifyImage $image */
                        $imageId = $image->ShopifyID;
                        $image->delete();
                        self::log("[$shopifyId][$imageId] Deleted image connected to product", self::SUCCESS);
                    }

                    foreach ($product->Variants() as $variant) {
                        /** @var ShopifyProductVariant $variant */
                        $variantId = $variant->ShopifyID;
                        $variant->delete();
                        self::log("[$shopifyId][$variantId] Deleted variant connected to product", self::SUCCESS);
                    }

                    $product->publish('Live', 'Stage', false);
                    $product->delete();
                    self::log("[$shopifyId] Deleted product and it's connections", self::SUCCESS);
                }
            }
        }
    }

    /**
     * Import the SHopify Collections
     * @param ShopifyClient $client
     *
     * @throws ValidationException
     */
    public function importCollections(ShopifyClient $client, $sinceId = 0)
    {
        try {
            $collections = $client->collections([
                'query' => [
                    'limit' => 250,
                    'since_id' => $sinceId
                ]
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            exit($e->getMessage());
        }

        if (($collections = $collections->getBody()->getContents()) && $collections = Convert::json2obj($collections)) {
            $lastId = $sinceId;
            foreach ($collections->custom_collections as $shopifyCollection) {
                // Create the collection
                if ($collection = $this->importObject(ShopifyCollection::class, $shopifyCollection)) {
                    // Create the images
                    if (!empty($shopifyCollection->image)) {
                        // The collection image does not have an id so set it from the scr to prevent double importing the image
                        $image = $shopifyCollection->image;
                        $image->id = $image->src;
                        if ($image = $this->importObject(Image::class, $image)) {
                            $collection->ImageID = $image->ID;
                            if ($collection->isChanged()) {
                                $collection->write();
                            } else {
                                self::log("[{$collection->ID}] Collection {$collection->Title} has no change", self::SUCCESS);
                            }
                        }
                    }

                    if (!$collection->latestPublished()) {
                        $collection->publish('Stage', 'Live', false);
                        self::log("[{$collection->ID}] Published collection {$collection->Title} and it's connections", self::SUCCESS);
                    } else {
                        self::log("[{$collection->ID}] Collection {$collection->Title} is alreaddy published", self::SUCCESS);
                    }
                    $lastId = $collection->ShopifyID;
                } else {
                    self::log("[{$shopifyCollection->id}] Could not create collection", self::ERROR);
                }
            }

            if ($lastId !== $sinceId) {
                self::log("[{$sinceId}] Try to import the next page of collections since last id", self::SUCCESS);
                $this->importCollections($client, $lastId);
            }
        }
    }

    /**
     * Import the Shopify Collects
     * @param ShopifyClient $client
     *
     * @throws ValidationException
     */
    public function importCollects(ShopifyClient $client, $sinceId = 0)
    {
        try {
            $collects = $client->collects([
                'query' => [
                    'limit' => 250,
                    'since_id' => $sinceId
                ]
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            exit($e->getMessage());
        }

        if (($collects = $collects->getBody()->getContents()) && $collects = Convert::json2obj($collects)) {
            $lastId = $sinceId;
            foreach ($collects->collects as $shopifyCollect) {
                if (
                    ($collection = ShopifyCollection::getByShopifyID($shopifyCollect->collection_id))
                    && ($product = ShopifyProduct::getByShopifyID($shopifyCollect->product_id))
                ) {
                    $collection->Products()->add($product, [
                        'ShopifyID' => $shopifyCollect->id,
                        'SortValue' => $shopifyCollect->sort_value,
                        'Position' => $shopifyCollect->position,
                        'Imported' => true
                    ]);

                    $lastId = $shopifyCollect->id;
                    self::log("[{$shopifyCollect->id}] Created collect between Product[{$product->ID}] and Collection[{$collection->ID}]", self::SUCCESS);
                }
            }

            if ($lastId !== $sinceId) {
                self::log("[{$sinceId}] Try to import the next page of collects since last id", self::SUCCESS);
                $this->importCollects($client, $lastId);
            }
        }
    }

    // todo make this flexible so it's also usable for Products, Variants, Images, Collections.
    // if made flexible should also handle versions.
    public function beforeImportCollects()
    {
        // Set all imported values to 0
        DB::query("UPDATE `ShopifyCollection_Products` SET `Imported` = 0 WHERE 1");
    }

    public function afterImportCollects()
    {
        DB::query("DELETE FROM `ShopifyCollection_Products` WHERE `Imported` = 0");
    }

    /**
     * Import the base product
     *
     * @param ShopifyProduct|ShopifyProductVariant|ShopifyImage|string $class
     * @param $shopifyData
     * @return null|ShopifyProduct|ShopifyProductVariant|ShopifyImage
     */
    private function importObject($class, $shopifyData)
    {
        $object = null;
        try {
            $object = $class::findOrMakeFromShopifyData($shopifyData);
            self::log("[{$object->ID}] Created {$class} {$object->Title}", self::SUCCESS);
        } catch (Exception $e) {
            self::log($e->getMessage(), self::ERROR);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            self::log("[Guzzle error] {$e->getMessage()}", self::ERROR);
        }

        return $object;
    }

    /**
     * Loop the given data map and possible sub maps
     *
     * @param array $map
     * @param $object
     * @param $data
     */
    public static function loop_map($map, &$object, $data)
    {
        foreach ($map as $from => $to) {
            if (is_array($to) && is_object($data->{$from})) {
                self::loop_map($to, $object, $data->{$from});
            } elseif (property_exists($data, $from)) {
                $object->{$to} = $data->{$from};
            }
        }
    }

    /**
     * Log messages to the console or cron log
     *
     * @param $message
     * @param $code
     */
    protected static function log($message, $code = self::NOTICE)
    {
        switch ($code) {
            case self::ERROR:
                echo "[ ERROR ] {$message}\n";
                break;
            case self::WARN:
                echo "[WARNING] {$message}\n";
                break;
            case self::SUCCESS:
                echo "[SUCCESS] {$message}\n";
                break;
            case self::NOTICE:
            default:
                echo "[NOTICE ] {$message}\n";
                break;
        }
    }
}
