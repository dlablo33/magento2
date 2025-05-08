<?php

/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Hiddentechies\Next\Setup;

use Magento\Framework\Setup;

class Installer implements Setup\SampleData\InstallerInterface {

    /**
     * @var \Magento\CmsSampleData\Model\Page
     */
    private $page;

    /**
     * @var \Magento\CmsSampleData\Model\Block
     */
    private $block;

    /**
     * @param \Hiddentechies\Next\Model\Page $page
     * @param \Hiddentechies\Next\Model\Block $block
     */
    public function __construct(
    \Hiddentechies\Next\Model\Page $page, 
            \Hiddentechies\Next\Model\Block $block
    ) {
        $this->page = $page;
        $this->block = $block;
    }

    /**
     * {@inheritdoc}
     */
    public function install() {

        //$this->page->install(['Hiddentechies_Next::fixtures/pages/pages.csv']);
        $this->page->install(
                [

                    'Hiddentechies_Next::DemoPages/pages.csv',
                ]
        );
        $this->block->install(
                [

                    'Hiddentechies_Next::DemoBlocks/blocks.csv',
                ]
        );
    }

}
