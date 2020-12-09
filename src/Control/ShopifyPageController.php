<?php

/**
 * Class ShopifyPageController
 * @mixin ShopifyPage
 */
class ShopifyPageController extends Page_Controller
{
    private static $allowed_actions = [
        'product',
        'collection'
    ];

    /**
     * @var Product
     */
    public $product;

    /**
     * @var ShopifyCollection
     */
    public $collection;

    /**
     * Get the Child pages as a paginated list
     *
     * @return PaginatedList
     */
    public function ChildPages()
    {
        $type = $this->ChildrenClass;
        return PaginatedList::create(
            $type::get(),
            $this->getRequest()
        )->setPageLength($this->PageLimit);
    }

    public function collection(SS_HTTPRequest $request)
    {
        if (!$urlSegment = $request->param('ID')) {
            $this->httpError(404);
        }

        /** @var ShopifyCollection $collection */
        if (!$collection = DataObject::get_one(ShopifyCollection::class, ['URLSegment' => $urlSegment])) {
            $this->httpError(404);
        }

        $this->collection = $collection;
        return $this->render($collection);
    }

    public function product(SS_HTTPRequest $request)
    {
        if (!$urlSegment = $request->param('ID')) {
            $this->httpError(404);
        }

        /** @var Product $product */
        if (!$product = DataObject::get_one(Product::class, ['URLSegment' => $urlSegment])) {
            $this->httpError(404);
        }

        $this->product = $product;
        return $this->render($product);
    }
}
