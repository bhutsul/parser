<?php

namespace App\Feeds\Vendors\LDS;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://leodaniels.com/sitemap.xml'];
    public array $custom_products = ['https://leodaniels.com//t0624271705700-men-s-t-navigator-black-dial-black-rubber-band-automatic-watch.html'];

    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        if ( $fi->isGroup() ) {
            $fi->setChildProducts( array_values(
                array_filter( $fi->getChildProducts(), static fn( FeedItem $item ) => !empty( $item->getMpn() ) && count( $item->getImages() ) && $item->getCostToUs() > 0 )
            ) );
            return count( $fi->getChildProducts() );
        }

        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
    }
}
