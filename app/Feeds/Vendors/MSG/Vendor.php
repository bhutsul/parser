<?php

namespace App\Feeds\Vendors\MSG;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.miamiwholesalesunglasses.com/xml-sitemap.ashx'];
    public array $custom_products = [
//        'https://www.miamiwholesalesunglasses.com/sunglass-display---style-tr-16c.aspx',
//        'https://www.miamiwholesalesunglasses.com/black-sunglass-bag-pouch-h.aspx',
//        'https://www.miamiwholesalesunglasses.com/giselle-sunglasses---8gsl22320.aspx',
        'https://www.miamiwholesalesunglasses.com/sunglass-display---style-tr-16c.aspx',
    ];

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
