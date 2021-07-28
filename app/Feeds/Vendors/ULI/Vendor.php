<?php

namespace App\Feeds\Vendors\ULI;

use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Data;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use Illuminate\Support\Facades\Storage;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.uline.com/sitemap-us-product.xml.gz'];
    protected const USE_PROXY = true;
    protected const CHUNK_SIZE = 300;

//    public array $custom_products = ['https://www.uline.com/Product/Detail/H-1003/Pallet-Trucks/BT-Pallet-Truck-48-x-27'];

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
}
