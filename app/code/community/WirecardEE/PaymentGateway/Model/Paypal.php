<?php
/**
 * Shop System Plugins:
 * - Terms of Use can be found under:
 * https://github.com/wirecard/magento-ee/blob/master/_TERMS_OF_USE
 * - License can be found under:
 * https://github.com/wirecard/magento-ee/blob/master/LICENSE
 */

use Wirecard\PaymentSdk\Transaction\Transaction;

class WirecardEE_PaymentGateway_Model_Paypal extends Mage_Core_Model_Abstract
{
    /**
     * Return available transaction types for this payment.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => Transaction::TYPE_AUTHORIZATION,
                'label' => Mage::helper('catalog')->__('Authorization')
            ],
            [
                'value' => Transaction::TYPE_PURCHASE,
                'label' => Mage::helper('catalog')->__('Purchase')
            ]
        ];
    }
}
