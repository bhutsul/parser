<?php

namespace App\Feeds\Vendors\AVT;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.myartventure.com/store-products-sitemap.xml'];
    public array $custom_products = ['https://www.myartventure.com/product-page/maz-6440rr'];

    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
    }
}
