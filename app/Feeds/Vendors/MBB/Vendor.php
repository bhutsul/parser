<?php

namespace App\Feeds\Vendors\MBB;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.modernbeanbag.com/sitemap.xml'];

    public array $custom_products = ['https://www.modernbeanbag.com/product/the-hamptons-lounger-set-4-1-ottoman/'];

    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        return !empty( $fi->getMpn() ) && count( $fi->getImages() );
    }

    public function filterProductLinks( Link $link ): bool
    {
        return str_contains( $link->getUrl(), '/product/' );
    }
}