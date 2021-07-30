<?php

namespace App\Feeds\Vendors\ULE;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Data;
use Illuminate\Support\Facades\Storage;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.uline.com/sitemap-us-product.xml.gz'];
    protected const USE_PROXY = true;
    protected const CHUNK_SIZE = 2;
    protected array $headers = [
        'Connection' => 'keep-alive',
        'Accept' => '*/*',
    ];

    public function getProductsLinks( Data $data, string $url ): array
    {
        $content = $this->getDownloader()->get( $url );
        $file_name = preg_replace( '/(.*\/)/', '', $url );
        Storage::disk( 'temp' )->put( $file_name, $content );
        $gz_file = gzopen( Storage::path( 'temp' ) . '\\' . $file_name, 'r');
        $xml = gzread( $gz_file, 100000000 );
        gzclose( $gz_file );
        Storage::disk( 'temp' )->delete( $file_name );

        $data = new Data( $xml );
        return parent::getProductsLinks($data, $url);
    }

    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
    }
}
