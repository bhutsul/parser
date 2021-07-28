<?php

namespace App\Feeds\Vendors\ULI;

use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Data;
use Illuminate\Support\Facades\Storage;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.uline.com/sitemap-us-product.xml.gz'];
//    protected const USE_PROXY = true;
//    protected const CHUNK_SIZE = 300;
//    protected ?int $max_products = 10;
//
//    public array $custom_products = [
//        'https://www.uline.com/Product/Detail/H-1003/Pallet-Trucks/BT-Pallet-Truck-48-x-27',
//        'https://www.uline.com/Product/Detail/H-1006-NOSE/Hand-Trucks/Nose-Plate-for-Magliner-Hand-Truck-18-x-7-1-2',
//        'https://www.uline.com/Product/Detail/S-10588P/Bubble-Rolls/Bubble-Mask-Roll-24-x-125-1-2-Perforate',
//        'https://www.uline.com/Product/Detail/H-6761-54A/Industrial-Wire-Shelving/Chrome-Wire-Shelving-Add-On-Unit-60-x-30-x-54',
//        'https://www.uline.com/Product/Detail/H-1006-NOSE/Hand-Trucks/Nose-Plate-for-Magliner-Hand-Truck-18-x-7-1-2',
//        'https://www.uline.com/Product/Detail/S-21079-M/Nitrile-Gloves/Uline-Black-Industrial-Nitrile-Gloves-in-a-Bucket-Medium?model=S-21079-M&RootChecked=yes',
//    ];

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
