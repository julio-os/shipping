<?php
namespace Julio\Shipping\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use \Julio\Shipping\Model\Config\Source\Mode;
use \Magento\Shipping\Model\Shipment\Request;

/**
 * Class Carrier
 * @package Julio\Shipping\Model
 */
class Carrier extends AbstractCarrier implements CarrierInterface
{
    /**
     * Code
     */
    const CODE = 'custom';

    /**
     * Methods
     */
    const METHOD_STANDARD = 'standard';
    const METHOD_SAMEDAY = 'sameday';
    const METHOD_FREEOVER = 'freeover';

    /**
     * API Urls
     */
    const API_URL_SANDBOX = 'https://ws-sandbox.mbe-latam.com/ship/v2/';
    const API_URL_PRODUCTION = 'https://ws-prod.mbe-latam.com/ship/v2/';

    /**
     * MBE API
     */
    const MBE_ACTION_NEW_SHIPMENT = 'newshipment';
    const MBE_SHIPPING_SERVICE_DEFAULT = '5125';
    const MBE_SHIPPING_SERVICE_RPK_EXPRESS = '2006';
    const MBE_SHIPPING_SERVICE_MBE_LOCAL = '4333';
    const MBE_SHIPPING_SERVICE_ECONOEXPRESS = '2007';
    const MBE_SHIPPING_SERVICE_FDX_NACIOMAL_ECONOMICO = '2008';
    const MBE_SHIPPING_SERVICE_FDX_NACIONAL_DIA_SIGUIENTE = '2009';

    /**
     * @var ResultFactory
     */
    private $rateResultFactory;

    /**
     * @var \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory
     */
    private $rateMethodFactory;

    /**
     * Urls
     * @var array
     */
    protected $apiUrls = [
        Mode::SANDBOX => self::API_URL_SANDBOX,
        Mode::PRODUCTION => self::API_URL_PRODUCTION
    ];
    /**
     * @var \Magento\Framework\HTTP\ClientFactory
     */
    private $httpClientFactory;

    /**
     * Carrier constructor.
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param ResultFactory $rateResultFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param \Magento\Framework\HTTP\ClientFactory $httpClientFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Magento\Framework\HTTP\ClientFactory $httpClientFactory,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
        $this->rateResultFactory = $rateResultFactory;
        $this->rateMethodFactory = $rateMethodFactory;
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * Check if carrier has shipping label option available
     *
     * @return bool
     */
    public function isShippingLabelsAvailable()
    {
        return true;
    }

    /**
     * Check if carrier has shipping tracking option available
     *
     * @return bool
     */
    public function isTrackingAvailable()
    {
        return false;
    }

    /**
     * Collect and get rates
     *
     * @param RateRequest $request
     * @return \Magento\Framework\DataObject|bool|null
     * @api
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        /** @var ResultFactory $resultFactory */
        $result = $this->rateResultFactory->create();

        if ($this->getConfigData('standard_active')) {
            $method = $this->createStandardMethod($request);
            $result->append($method);
        }

        if ($this->getConfigData('sameday_active')) {
            $method = $this->createSameDayMethod($request);
            $result->append($method);
        }

        if ($this->getConfigData('freeover_active')) {
            if ($request->getPackageValue() > $this->getConfigData('freeover_amount')) {
                $method = $this->createFreeOverMethod($request);
                $result->append($method);
            }
        }

        return $result;
    }

    /**
     * Do request to shipment
     * Implementation must be in overridden method
     *
     * @param Request $request
     * @return \Magento\Framework\DataObject
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function requestToShipment($request)
    {
        $packages = $request->getPackages();
        if (!is_array($packages) || !$packages) {
            throw new \Magento\Framework\Exception\LocalizedException(__('No packages for request'));
        }
        $result = $this->doShipmentRequest($request);

        $response = new \Magento\Framework\DataObject([
            'info' => [
                [
                    'tracking_number' => $result['tracking'],
                    'label_content' => $result['label'],
                ]
            ]
        ]);

        $request->setMasterTrackingId($result['tracking']);

        return $response;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     * @api
     */
    public function getAllowedMethods()
    {
        return [
            'standard' => $this->getConfigData('standard_title'),
            'freeover' => __($this->getConfigData('freeover_title'), $this->getConfigData('freeover_amount')),
            'sameday' => $this->getConfigData('sameday_title')
        ];
    }

    /**
     * Standard Result factory
     * @param RateRequest $request
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    protected function createStandardMethod(RateRequest $request)
    {
        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->rateMethodFactory->create();

        $method->setCarrier(self::CODE);
        $method->setCarrierTitle($this->getConfigData('standard_title'));

        $method->setMethod(self::METHOD_STANDARD);
        $method->setMethodTitle($this->getConfigData('name'));

        $price = $this->getConfigData('standard_price');
        $method->setPrice($price);
        $method->setCost($price);

        return $method;
    }

    /**
     * Same day Result factory
     * @param RateRequest $request
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    protected function createSameDayMethod(RateRequest $request)
    {
        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->rateMethodFactory->create();

        $method->setCarrier(self::CODE);
        $method->setCarrierTitle($this->getConfigData('sameday_title'));

        $method->setMethod(self::METHOD_SAMEDAY);
        $method->setMethodTitle($this->getConfigData('name'));

        $price = $this->getConfigData('sameday_price');
        $method->setPrice($price);
        $method->setCost($price);

        return $method;
    }

    /**
     * Same day Result factory
     * @param RateRequest $request
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    protected function createFreeOverMethod(RateRequest $request)
    {
        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->rateMethodFactory->create();
        /** @var \Magento\Directory\Model\Currency $currency */
        $currency = $request->getBaseCurrency();
        $amount = $this->getConfigData('freeover_amount');
        $amount = $currency->formatTxt($amount);

        $method->setCarrier(self::CODE);
        $method->setCarrierTitle($this->getConfigData('freeover_title'), $amount);

        $method->setMethod(self::METHOD_FREEOVER);
        $method->setMethodTitle($this->getConfigData('name'));

        $method->setPrice(0);
        $method->setCost(0);

        return $method;
    }

    /**
     * Api Url
     * @return string
     */
    protected function getApiUrl()
    {
        $mode = $this->getConfigData('mbe_api_mode');

        return $this->apiUrls[$mode];
    }

    /**
     * Shipment request
     * @param Request $request
     * @return array
     * @throws LocalizedException
     * @throws \Zend_Json_Exception
     */
    protected function doShipmentRequest(Request $request)
    {
        $requestData = $this->prepareRequestData($request);
        $result = $this->fetch($requestData);

        return $result;
    }

    /**
     * Prepares request data
     * @param Request $request
     * @return array
     */
    protected function prepareRequestData(Request $request)
    {
        $shipment = $request->getOrderShipment();
        $order = $shipment->getOrder();
        $address = $order->getShippingAddress();

        $data = [
            'token' => $this->getConfigData('mbe_api_token'),
            'action' => self::MBE_ACTION_NEW_SHIPMENT,
            'order_number' => $order->getIncrementId(),
            'ref_number' => '',
            'order_total' => 0,
            'order_currency' => $order->getOrderCurrencyCode(),
            'label' => 1,
            'shipping_service' => self::MBE_SHIPPING_SERVICE_DEFAULT,
            'recipient_name' => $address->getName(),
            'recipient_city' => $address->getCity(),
            'recipient_state' => $address->getRegion(),
            'recipient_cp' => preg_replace('/[^0-9]/','',  $address->getPostcode()),
            'recipient_country' => $address->getCountryId(),
            'recipient_phone' => $address->getTelephone(),
            'recipient_email' => $address->getEmail(),
            'recipient_add1' => '',
            'recipient_add2' => '-'
        ];

        $streetLine = 1;
        foreach ($address->getStreet() as $street) {
            $data['recipient_add' . $streetLine] = $street;
            $streetLine++;
        }

        foreach ($request->getPackages() as $package) {
            $data = array_merge($data, [
                'package_weight_unit' => 'K',
                'package_dim_unit' => 'cm',
                'package_weight' => $package['params']['weight'],
                'package_length' => $package['params']['length'],
                'package_width' => $package['params']['width'],
                'package_height' => $package['params']['height']
            ]);
        }

        return $data;
    }

    /**
     * Fetch API data
     * @param array $data
     * @return mixed
     * @throws LocalizedException
     * @throws \Zend_Json_Exception
     */
    protected function fetch(array $data)
    {
        $client = $this->httpClientFactory->create();
        try {
            $client->post($this->getApiUrl(), $data);

            $body = $client->getBody();
            $response = \Zend_Json::decode($body);
        } catch (\Exception $e) {
            $this->_logger->critical(sprintf('Could not fetch label for Order: %s (%s)', $data['order_number'], $e->getMessage()));
            throw new LocalizedException(__('Could not fetch Label for shipment'));
        }

        if (isset($response['error'])) {
            throw new LocalizedException(__($response['error']));
        }

        if (isset($response['label'])) {
            $response['label'] = base64_decode($response['label']);
        }

        return $response;
    }
}