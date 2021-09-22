<?php

namespace App\Feeds\Vendors\MSG;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\HttpHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const NOT_VALID_ATTRIBUTES = [
        'contact_us',
    ];

    public const NOT_VALID_OPTIONS = [
        'custom',
        'we will call',
    ];

    public const NOT_VALID_PARTS_OF_DESC_REGEXES = [
        '/<strong\b[^>]*><span\b[^>]*>\*Receive.*?checkout.<\/strong>/ui',
        '/<strong\b[^>]*>If you need.*?order \*{1,3}<\/strong>/ui',
        '/<br>Approx Dimensions:.*?<br><br>/ui',
        '/<br><br>Approx. Dimensions:.*?<br><br>/ui',
    ];

    public const FEATURES_REGEXES = [
        '/<(div|p|span|b|strong|h\d|em)>Features(s)?:?(?<content_list><br>.*?<br><br>)/is',
    ];

    private string $name = '';
    private float $price = 0;
    private array|null $attributes = null;
    private string $description = '';
    private array $short_description = [];
    private array $dims = [];

    private function parseAttributesAndShorts(): array
    {
        $attributes = [];
        $short_description = [];

        $this->filter( '.product-attributes .product-attribute' )->each( function ( ParserCrawler $c ) use ( &$attributes, &$short_description ) {
            $text = $c->text();
            if ( str_contains( $text, ':' ) ) {
                [ $key, $value ] = explode( ':', $text, 2 );
                $attributes[ trim( $key ) ] = trim( StringHelper::normalizeSpaceInString( $value ) );
            }
            else {
                $short_description[] = $text;
            }
        } );

        return [
            FeedHelper::cleanShortDescription( $short_description ),
            FeedHelper::cleanAttributes( $attributes ),
        ];
    }

    private function parsePrice(): float
    {
        $price = $this->getText( '.prod-detail-price .prod-detail-cost-value' );

        if ( preg_match( '/(regularly[\s]?\$[\s]?\d+[,]?[.]?\d*[,]?[.]?\d*?)/ui', $price, $matches ) && isset( $matches[ 1 ] ) ) {
            $price = $matches[ 1 ];
        }

        return StringHelper::getFloat( $price, 0 );
    }

    private function validatedAttributes( array|null $attributes ): array|null
    {
        if ( !isset( $attributes ) ) {
            return null;
        }

        $validated = [];

        foreach ( $attributes as $key => $value ) {
            if ( false !== stripos( $key, 'sold by the' ) ) {
                $this->name .= " ( $key: $value )";
                break;
            }

            foreach ( self::NOT_VALID_ATTRIBUTES as $not_valid_attribute ) {
                if ( false !== stripos( $key, $not_valid_attribute ) || false !== stripos( $value, $not_valid_attribute ) ) {
                    break;
                }
                $validated[ $key ] = $value;
            }
        }

        return $validated;
    }

    private function parseDims( string $description ): array
    {
        $dims = [
            'x' => null,
            'y' => null,
            'z' => null,
        ];

        if ( preg_match( '/<br>Approx Dimensions:(.*?)<br><br>/ui', $description, $matches ) && isset( $matches[ 1 ] ) ) {
            $dims_values = array_filter( explode( '<br>', $matches[ 1 ] ) );
            foreach ( $dims_values as $text ) {
                [ $key, $value ] = explode( ':', $text, 2 );

                switch ( $key ) {
                    case 'Height':
                        $dims[ 'y' ] = StringHelper::getFloat( $value );
                        break;
                    case 'Width':
                        $dims[ 'x' ] = StringHelper::getFloat( $value );
                        break;
                    case 'Depth':
                        $dims[ 'z' ] = StringHelper::getFloat( $value );
                        break;
                }
            }
            return $dims;
        }

        if ( preg_match( '/<br><br>Approx. Dimensions:.*?<br><br>/ui', $description, $matches ) && isset( $matches[ 0 ] ) ) {
            $dims = FeedHelper::getDimsInString( $matches[ 0 ], 'x' );
        }

        return $dims;
    }

    public function beforeParse(): void
    {
        $this->name = $this->getText( '#product-detail-div h1' );
        $this->price = $this->parsePrice();

        [ $short_description, $attributes ] = $this->parseAttributesAndShorts();

        if ( $this->exists( '.prod-detail-desc' ) ) {
            $description = $this->getHtml( '.prod-detail-desc' );
            $this->dims = $this->parseDims( $description );

            $description = (string)preg_replace( self::NOT_VALID_PARTS_OF_DESC_REGEXES, '', $description );
            $additional_info = FeedHelper::getShortsAndAttributesInDescription( $description, self::FEATURES_REGEXES, $short_description, $attributes );
            $short_description = $additional_info[ 'short_description' ];
            $attributes = $additional_info[ 'attributes' ];

            $this->description = $additional_info[ 'description' ];
        }

        $this->short_description = $short_description;
        $this->attributes = $this->validatedAttributes( $attributes );
    }

    private function formattedImages( array $images ): array
    {
        return array_values( array_unique( array_map( static fn( $image ) => 'https://www.miamiwholesalesunglasses.com' . $image, $images ) ) );
    }

    private function avail( string $text ): int
    {
        return $text === 'In Stock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function isGroup(): bool
    {
        return $this->exists( '.prod-detail-rt .variationDropdownPanel' );
    }

    public function getProduct(): string
    {
        return $this->name;
    }

    public function getMpn(): string
    {
        return $this->getText( 'span.prod-detail-part-value' );
    }

    public function getDescription(): string
    {
        return !empty( $this->description ) ? $this->description : $this->getProduct();
    }

    public function getShortDescription(): array
    {
        return $this->short_description;
    }

    public function getImages(): array
    {
        return $this->formattedImages( $this->getAttrs( '.prod-detail-lt a', 'href' ) );
    }

    public function getAttributes(): ?array
    {
        return !empty( $this->attributes ) ? $this->attributes : null;
    }

    public function getCostToUs(): float
    {
        return $this->price;
    }

    public function getAvail(): ?int
    {
        return $this->avail( $this->getText( '.prod-detail-stock' ) );
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.breadcrumb a' ), 1 ) );
    }

    public function getDimX(): ?float
    {
        return $this->dims[ 'x' ] ?? null;
    }

    public function getDimY(): ?float
    {
        return $this->dims[ 'y' ] ?? null;
    }

    public function getDimZ(): ?float
    {
        return $this->dims[ 'z' ] ?? null;
    }

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];

        $this->filter( '.prod-detail-rt .variationDropdownPanel select option' )->each( function ( ParserCrawler $option ) use ( $parent_fi, &$child ) {
            if ( $option->attr( 'value' ) && false === stripos( $option->attr( 'value' ), 'select' ) ) {
                $name = $option->parents()->parents()->parents()->parents()->getText( '.label' );
                $name .= str_ends_with( $name, ':' ) ? ' ' : ': ';
                $name .= $option->text();
                $name .= str_ends_with( $name, '.' ) ? '' : '.';

                $key = $option->parents()->getAttr('select', 'name');
                $html = $this->getVendor()->getDownloader()->post( $this->getUri(), [
                    'ctl00$pageContent$scriptManager' => 'ctl00$pageContent$productDetailUpdatePanel|' . $key,
                    '__VIEWSTATE' => $this->getAttr( 'input[name="__VIEWSTATE"]', 'value' ),
                    '__EVENTVALIDATION' => $this->getAttr( 'input[name="__EVENTVALIDATION"]', 'value' ),
                    $key => $option->attr( 'value' ),
                ] )->getData();

                $crawler = new ParserCrawler( $html );

                $fi = clone $parent_fi;

                $fi->setMpn( $crawler->getText( 'span.prod-detail-part-value' ) );
                $fi->setProduct( $name );
                $fi->setImages( $this->formattedImages( $crawler->getAttrs( '.prod-detail-lt a', 'href' ) ) );
                $fi->setCostToUs( $this->getCostToUs() );
                $fi->setRAvail( $this->avail( $crawler->getText( '.prod-detail-stock' ) ) );
                $fi->setDimZ( $this->getDimZ() );
                $fi->setDimY( $this->getDimY() );
                $fi->setDimX( $this->getDimX() );

                $child[] = $fi;
            }
        } );

        return $child;
    }

    public function getOptions(): array
    {
        $options = [];

        if ( $this->exists( '#ctl00_pageContent_ppQuestions_questions' ) ) {
            $this->filter( '#ctl00_pageContent_ppQuestions_questions select' )->each( function ( ParserCrawler $select ) use ( &$options ) {
                $name = $select->parents()->parents()->parents()->getText( '.personalization-question-label' );

                if ( !$name ) {
                    return;
                }
                $name = StringHelper::removeSpaces( $name );

                $option_values = [];
                $select->filter( 'option' )->each( function ( ParserCrawler $option ) use ( &$option_values ) {
                    if ( $option->attr( 'value' ) ) {
                        foreach ( self::NOT_VALID_OPTIONS as $not_valid_option ) {
                            if ( false !== stripos( $option->text(), $not_valid_option ) ) {
                                return;
                            }
                        }
                        $option_values[] = StringHelper::removeSpaces( $option->text() );
                    }
                } );

                if ( !$option_values ) {
                    return;
                }

                $options[ $name ] = $option_values;
            } );
        }

        return $options;
    }
}
