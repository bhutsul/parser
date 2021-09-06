<?php

namespace App\Feeds\Vendors\TSL;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const OPTION_LINK = 'https://www.taylorsecurity.com/ajax/store/ajax.aspx';
    public const NOT_VALID_PARTS_OF_DESC = [
        'sales@taylorsecurity.com',
        '1-800-676-7670',
    ];

    private array $product_info;

    private function pushFilesAndVideos(): void
    {
        if ($this->exists('#tab-templates')) {
            $this->filter('#tab-templates a')->each(function (ParserCrawler $a) {
                $this->pushFileFromA($a);
            });

            $this->filter('#tab-templates iframe')->each(function (ParserCrawler $iframe) {
                $this->pushVideoFromIframe($iframe);
            });
        }

        if ($this->exists('#tab-description')) {
            $this->filter('#tab-description a')->each(function (ParserCrawler $a) {
                $this->pushFileFromA($a);
            });
        }

        if ($this->exists('#tab-video')) {
            $this->filter('#tab-video iframe')->each(function (ParserCrawler $iframe) {
                $this->pushVideoFromIframe($iframe);
            });
        }
    }

    private function pushVideoFromIframe(ParserCrawler $iframe): void
    {
        $this->product_info['videos'][] = [
            'name' => $this->getProduct(),
            'provider' => 'youtube',
            'video' => $iframe->attr('src'),
        ];
    }

    private function pushFileFromA(ParserCrawler $a): void
    {
        if (false !== stripos($a->attr('href'), 'pdf')) {
            $this->product_info['files'][] = [
                'name' => trim(str_replace("Download", '', $a->text())),
                'link' => $a->attr('href')
            ];
        }
    }

    private function formattedChildProperties(array $item_properties, array $options, array $option_names, int $item_id, int $value_id): array
    {
        $properties = [];

        foreach ($item_properties as $property) {
            switch ($property['N']) {
                case 'SKU':
                    $properties['mpn'] = $property['V'];
                    break;
                case 'Price':
                    $properties['price'] = $property['V'];
                    break;
                case 'Images':
                    $properties['images'] = !empty($property['V']) ? $this->formattedImages((new ParserCrawler($property['V']))->getAttrs('a', 'href')) : [];
                    break;
            }
        }

        $properties['name']  = '';
        foreach ($options as $id) {
            $properties['name']  .= $option_names[$id];
        }

        if ($this->priceNotValid($properties['price'])) {
            $properties['price'] = $this->fetchPrice($item_id, $value_id);
        }

        return $properties;
    }

    private function formattedImages(array $images): array
    {
        return array_values(
            array_filter(
                array_map(static fn( $image ) => false === stripos($image, 'https') ? "https://www.taylorsecurity.com$image" : $image, $images ),
                static fn( $image ) => $image !== null && false === stripos( $image, 'no_image' )
            )
        );
    }

    private function parseDescription(): string
    {
        $description = '';

        $this->filter( '#tab-description p' )->each( function ( ParserCrawler $c ) use ( &$description ) {
            if ( $c->text() ) {
                $not_valid = false;
                foreach ( self::NOT_VALID_PARTS_OF_DESC as $text ) {
                    if ( false !== stripos( $c->text(), $text ) ) {
                        $not_valid = true;
                    }
                }

                if ( $not_valid === false ) {
                    $description .= '<p>' . $c->text() . '</p>';
                }
            }
        } );

        return $description;
    }

    private function parsePrice(): float
    {
        if ($this->isGroup() || !$this->exists('#CT_ItemDetailsBottom_2_lblPrice')) {
            return 0;
        }

        $price = $this->getText('#CT_ItemDetailsBottom_2_lblPrice');

        if ($this->priceNotValid($price)) {
            if (!isset($this->product_info['options'])) {
                return 0;
            }
            $price = $this->fetchPrice($this->product_info['options']['SelectedItem'], $this->product_info['options']['ValueId']);
        }

        return StringHelper::getFloat($price);
    }

    private function fetchPrice(int $item_id, int $value_id): int|string
    {
        $price = $this->getVendor()->getDownloader()->post(self::OPTION_LINK, [
            'F' => 'Add2CartTable',
            'ItemId' => $item_id,
            'ValueId' => $value_id,
            'Qty' => 1,
            'Recipient' => 'Myself',
            'IsMobile' => false,
        ]);
        $price = json_decode($price->getData(), true, 512, JSON_THROW_ON_ERROR);

        return $price['TotalPrice'];
    }

    private function fetchChild(): \Generator
    {
        if (!isset($this->product_info['options']['Items'])) {
            return [];
        }

        $links = [];
        $items = [];

        foreach ($this->product_info['options']['Items'] as $group_of_options) {
            $value_id = $group_of_options['s'][array_key_first($group_of_options['s'])];

            $link = new Link(self::OPTION_LINK, 'get', [
                'F' => 'GetSelectionItemInfo',
                'SelectionId' => $value_id,
                'ItemId' => $group_of_options['i'],
            ]);

            $items[$link->getUrl()] = [
                'item_id' => $group_of_options['i'],
                'value_id' => $value_id,
                'options' => $group_of_options['s'],
            ];

            $links[] = $link;
        }

        $option_names = $this->optionNames();
        $child = $this->getVendor()->getDownloader()->fetch($links);

        foreach ($child as $link => $item) {
            $item = json_decode($item->getData(), true, 512, JSON_THROW_ON_ERROR);

            yield $this->formattedChildProperties($item['ItemProperties'], $items[$link]['options'], $option_names, $items[$link]['item_id'], $items[$link]['value_id']);
        }
    }

    private function priceNotValid(string $price): bool
    {
        return false !== stripos($price, 'add to cart');
    }

    private function optionNames(): array
    {
        if (!isset($this->product_info['options']['Classifications'])) {
            return [];
        }

        $names = [];
        foreach ($this->product_info['options']['Classifications'] as $group) {
            foreach ($group['s'] as $option) {
                $names[$option['i']] = $group['n'] . ': ' . $option['s'] . '. ';
            }
        }

        return $names;
    }

    public function beforeParse(): void
    {
        $this->pushFilesAndVideos();
        $this->product_info['description'] = $this->parseDescription();

        preg_match('/IdevSelections\([\s*]?({.*?})[\s*]?\);/s', $this->node->html(), $matches);
        if (isset($matches[1])) {
            $this->product_info['options'] = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        }
        $this->product_info['price'] = $this->parsePrice();
    }

    public function isGroup(): bool
    {
        return isset($this->product_info['options']['Classifications']) && count($this->product_info['options']['Classifications']);
    }

    public function getProduct(): string
    {
        return $this->getText('h1[itemprop="name"]');
    }

    public function getBrand(): ?string
    {
        return $this->getText('span[itemprop="brand"]');
    }

    public function getMpn(): string
    {
        return $this->getText('span[itemprop="sku"]');
    }

    public function getDescription(): string
    {
        return $this->product_info['description'] ?? '';
    }

    public function getImages(): array
    {
        $images = $this->getAttrs('#altImagesViewer a', 'href');

        if (!$images) {
            $images = [$this->getAttr('#imageViewer img', 'src')];
        }

        if ($this->exists('#tab-specifications img')) {
            $images[] = $this->getAttr('#tab-specifications p img', 'src');
        }

        return $this->formattedImages($images);
    }

    public function getVideos(): array
    {
        return $this->product_info['videos'] ?? [];
    }

    public function getProductFiles(): array
    {
        return $this->product_info['files'] ?? [];
    }

    public function getAttributes(): ?array
    {
        return $this->product_info['attributes'] ?? null;
    }

    public function getCostToUs(): float
    {
        return $this->product_info['price'] ?? 0;
    }

    public function getAvail(): ?int
    {
        return $this->getAttr('span[property="og:availability"]', 'content') === 'InStock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function getCategories(): array
    {
        return array_values(array_slice($this->getContent('.breadcrumbs a'), 2, -1));
    }

    public function getChildProducts(FeedItem $parent_fi): array
    {
        $child = [];

        foreach ($this->fetchChild() as $item) {
            $fi = clone $parent_fi;
            $fi->setProduct($item['name']);
            $fi->setMpn($item['mpn']);
            $fi->setImages($item['images']);
            $fi->setCostToUs(StringHelper::getFloat($item['price']));
            $fi->setRAvail($this->getAvail());

            $child[] = $fi;
        }

        return $child;
    }
}
