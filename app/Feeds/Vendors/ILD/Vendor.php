<?php

namespace App\Feeds\Vendors\ILD;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://ivylanedesign.com/sitemap.xml'];
    public array $custom_products = [
//        'https://ivylanedesign.com/single-initial-venise-handkerchief.html',
//        'https://ivylanedesign.com/wine-frame.html',
//        'https:\/\/ivylanedesign.com\/glamour-memory-book.html',
//        'https:\/\/ivylanedesign.com\/las-vegas-unity-candle.html',
//        'https:\/\/ivylanedesign.com\/amour-ring-pillow.html',
//        'https:\/\/ivylanedesign.com\/glamour-guest-book.html',
//        'https:\/\/ivylanedesign.com\/adelaide-flower-girl-basket.html',
//        'https:\/\/ivylanedesign.com\/garbo-guest-book.html',
//        'https:\/\/ivylanedesign.com\/garbo-ring-pillow.html',
//        'https:\/\/ivylanedesign.com\/catalog\/product\/view\/id\/354',
//        'https:\/\/ivylanedesign.com\/fancier-chalkboard-stickers-5pk.html',
//        'https:\/\/ivylanedesign.com\/chalkboard-sign-with-easel.html',
//        'https:\/\/ivylanedesign.com\/florence-flower-girl-basket.html',
//        'https:\/\/ivylanedesign.com\/seashore-pen-holder.html',
//        'https://ivylanedesign.com//last-name-burlap-sign.html',
//        'https:\/\/ivylanedesign.com\/seashore-guest-book.html',
//        'https:\/\/ivylanedesign.com\/seashore-8x8-scrapbook-album.html',
//        'https:\/\/ivylanedesign.com\/adelaide-pen-holder.html"',
//        'https:\/\/ivylanedesign.com\/welcome-burlap-signi.html',
//        'https:\/\/ivylanedesign.com\/florence-ring-pillow.html',
    ];

//    /**
//     * @param FeedItem $fi
//     * @return bool
//     */
//    protected function isValidFeedItem(FeedItem $fi ): bool
//    {
//        if ( $fi->isGroup() ) {
//            $fi->setChildProducts( array_values(
//                array_filter( $fi->getChildProducts(), static fn( FeedItem $item ) => !empty( $item->getMpn() ) && count( $item->getImages() ) && $item->getCostToUs() > 0 )
//            ) );
//            return count( $fi->getChildProducts() );
//        }
//
//        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
//    }
}
