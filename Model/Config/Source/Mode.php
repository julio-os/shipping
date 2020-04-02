<?php
namespace Julio\Shipping\Model\Config\Source;

/**
 * @api
 * @since 100.0.2
 */
class Mode implements \Magento\Framework\Option\ArrayInterface
{
    const SANDBOX = 'sandbox';
    const PRODUCTION = 'production';

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::SANDBOX, 'label' => __('Sandbox')],
            ['value' => self::PRODUCTION, 'label' => __('Production')]
        ];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return [0 => __('Sandbox'), 1 => __('Production')];
    }
}
