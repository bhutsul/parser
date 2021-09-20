<?php

namespace App\Feeds\Vendors\PST;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\HttpProcessor;
use App\Feeds\Utils\Data;


class Vendor extends HttpProcessor
{
    protected array $first = ['https://www.etsy.com/'];

    public const CATEGORY_LINK_CSS_SELECTORS = [ '#desktop-category-nav li a', '[data-appears-component-name="search_pagination"] a' ];
    public const PRODUCT_LINK_CSS_SELECTORS = [ '[data-search-results] .listing-link' ];
    protected array $headers = [
        'Connection' => 'keep-alive',
        'Accept' => '*/*',
    ];
    protected const DELAY_S = 0.2;

    public array $custom_products = [
        'https://www.etsy.com/listing/1061367836/mens-green-3-piece-suits-wedding-groom?ga_order=most_relevant&ga_search_type=all&ga_view_type=gallery&ref=sc_gallery-1-3&plkey=04f6496c74fe94688db4cafc8945e74f187a5a2a%3A1061367836&pro=1&frs=1&variation1=2214675017&variation0=2214675005',
        'https://www.etsy.com/listing/1038007072/young-threads-multi-patchwork-boho-maxi?ga_order=most_relevant&ga_search_type=all&ga_view_type=gallery&ref=sc_gallery-1-3&plkey=d2b2c93bf77f0754f201d2a749f5a4a0af901b58%3A1038007072&pro=1&variation0=2121908042',
        'https://www.etsy.com/listing/541021061/opal-raw-crystals-a-grade-small-bulk-raw?ga_order=most_relevant&ga_search_type=all&ga_view_type=gallery&ga_search_query=&ref=sc_gallery-5-2&plkey=0c722cb721902539476fab6cc571041aa5a2e13b%3A541021061&pro=1',
        'https://www.etsy.com/listing/920777806/double-knot-headband-celtic-knot?ga_order=most_relevant&ga_search_type=all&ga_view_type=gallery&ga_search_query=&ref=sc_gallery-1-1&plkey=ca3f6edb251a8029a0635fba6fcdc792d9e5890d%3A920777806&bes=1',
        'https://www.etsy.com/listing/158391751/awesome-dorotka-traditional-string?ga_order=most_relevant&ga_search_type=all&ga_view_type=gallery&ga_search_query=Marionettes&ref=sr_gallery-1-5&organic_search_click=1&cns=1',
        'https://www.etsy.com/listing/257648566/to-do-planner-stickers?ga_order=most_relevant&ga_search_type=all&ga_view_type=gallery&ga_search_query=&ref=sr_gallery-1-9&pro=1',
        'https://www.etsy.com/listing/1038629947/rose-gold-wedding-glasses-and-cake?ga_order=most_relevant&ga_search_type=all&ga_view_type=gallery&ga_search_query=&ref=sc_gallery-1-1&plkey=3e92f16e8a9d3f4455b6dd686bee33269d8ca911%3A1038629947&pro=1&frs=1',
    ];

    public function beforeProcess(): void
    {
        $this->getDownloader()->removeCookies();
        $this->getDownloader()->setCookie('user_prefs', 'BDyTA0mzWY2CZmRsOidxBAP1_spjZACCRA_tQxDaQCdaKTTYRUknrzQnR0cpNU83NFhJRynUESpiBKFwEbEMAA..');
    }

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
