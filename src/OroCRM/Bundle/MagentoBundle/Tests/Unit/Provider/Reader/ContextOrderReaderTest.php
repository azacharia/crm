<?php

namespace OroCRM\Bundle\MagentoBundle\Tests\Unit\Provider\Reader;

use OroCRM\Bundle\MagentoBundle\Provider\Reader\ContextOrderReader;

class ContextOrderReaderTest extends AbstractContextReaderTest
{
    /**
     * @return ContextOrderReader
     */
    protected function getReader()
    {
        return new ContextOrderReader($this->contextRegistry);
    }

    /**
     * {@inheritdoc}
     */
    protected function getData()
    {
        return [
            ['customer' => ['originId' => 1]],
            ['customer' => ['originId' => 2]],
            ['customer' => ['originId' => 3]]
        ];
    }
}
