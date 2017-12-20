<?php

use Razorpay\Api\Api;
use Razorpay\Api\Errors;
use Razorpay\Woocommerce\Errors as WooErrors;

class RZP_Subscriptions
{
    /**
     * @var WC_Razorpay
     */
    protected $razorpay;

    /**
     * @var Api
     */
    protected $api;

    /**
     * @var string
     */
    protected $keyId;

    /**
     * @var string
     */
    protected $keySecret;

    const RAZORPAY_SUBSCRIPTION_ID       = 'razorpay_subscription_id';
    const RAZORPAY_PLAN_ID               = 'razorpay_wc_plan_id';
    const INR                            = 'INR';

    public function __construct($keyId, $keySecret)
    {
        $this->api = new Api($keyId, $keySecret);

        $this->razorpay = new WC_Razorpay(false);

    }

    public function createSubscription($orderId)
    {
        global $woocommerce;

        $subscriptionData = $this->getSubscriptionCreateData($orderId);

        try
        {
            $subscription = $this->api->subscription->create($subscriptionData);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_SUBSCRIPTION_CREATION_FAILED,
                400
            );
        }

        // Setting the subscription id as the session variable
        $sessionKey = $this->getSubscriptionSessionKey($orderId);

        $woocommerce->session->set($sessionKey, $subscription['id']);

        return $subscription['id'];
    }

    public function cancelSubscription($subscriptionId)
    {
        try
        {
            $this->api->subscription->fetch($subscriptionId)->cancel($subscriptionId);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_SUBSCRIPTION_CANCELLATION_FAILED,
                400
            );
        }
    }

    private function getWooCommerceSubscriptionFromOrderId($orderId)
    {
        $subscriptions = wcs_get_subscriptions_for_order($orderId);

        return end($subscriptions);
    }

    protected function getSubscriptionCreateData($orderId)
    {
        $order = new WC_Order($orderId);

        // $sub = $this->getWooCommerceSubscriptionFromOrderId($orderId);

        $product = $this->getProductFromOrder($order);

        $planId = $this->getProductPlanId($product, $order);

        $customerId = $this->getCustomerId($order);

        $length = (int) WC_Subscriptions_Product::get_length($product['product_id']);

        $subscriptionData = array(
            // TODO: Doesn't work with trial periods currently
            // 'start_at'        => $sub->get_time('date_created'),
            'customer_id'     => $customerId,
            'plan_id'         => $planId,
            'quantity'        => (int) $product['qty'],
            'total_count'     => $length,
            'customer_notify' => 0,
            'notes'           => array(
                'woocommerce_order_id'   => $orderId,
                'woocommerce_product_id' => $product['product_id']
            ),
        );

        $signUpFee = WC_Subscriptions_Product::get_sign_up_fee($product['product_id']);

        // We pass $subscriptionData and $signUpFee by reference
        $this->setStartAtAndSignUpFeeIfNeeded($subscriptionData, $signUpFee, $order);

        // We add the signup fee as an addon
        if ($signUpFee)
        {
            $item = array(
                'amount'   => (int) round($signUpFee * 100),
                'currency' => get_woocommerce_currency(),
                'name'     => $product['name']
            );

            if ($item['currency'] !== self::INR)
            {
                $this->razorpay->handleCurrencyConversion($item);
            }

            $subscriptionData['addons'] = array(array('item' => $item));
        }

        return $subscriptionData;
    }

    /**
     * @param $subscriptionData
     * @param $signUpFee
     * @param $order
     * @throws Errors\Error
     */
    protected function setStartAtAndSignUpFeeIfNeeded(& $subscriptionData, & $signUpFee, $order)
    {
        $product = $this->getProductFromOrder($order);

        $metadata = get_post_meta($product['product_id']);

        if (empty($metadata['razorpay_wc_start_date']) === false)
        {
            //
            // When custom start date is set, we consider the initial payment
            // as a sign up payment, and the first recurring payment will be
            // made on the configured start date on the next month
            //
            $startDay = $metadata['razorpay_wc_start_date'][0];

            //
            // $startDay must be in between 1 and 28
            //
            if (($startDay <= 1) or ($startDay >= 28))
            {
                throw new Errors\Error(
                    'Invalid start day saved as subscription product metadata',
                    WooErrors\SubscriptionErrorCode::SUBSCRIPTION_START_DATE_INVALID,
                    400
                );
            }

            $sub = $this->getWooCommerceSubscriptionFromOrderId($order->get_id());

            $startDate = $this->getStartDate($startDay, $sub);

            // We modify the sign up fee which was passed by reference
            $signUpFee += $sub->get_total();

            $subscriptionData['start_at'] = $startDate;

            //
            // In the case where we take the first recurring payment as a up front amount, and the
            // second recurring payment as the first recurring payment, we reduce the total count by 1
            //
            $subscriptionData['total_count'] = $subscriptionData['total_count'] - 1;
        }
    }

    protected function getStartDate($startDay, $sub)
    {
        $period = $sub->get_billing_period();

        $interval = $sub->get_billing_interval();

        $date = new DateTime('now');

        //
        // We get the date one interval ahead from the current date. The interval depends
        // on the settings for the subscriptions product. For eg. If the interval is yearly,
        // and the current date is 21/10/2017, then $oneIntervalAhead would be 21/10/2018.
        //
        $oneIntervalAhead = $date->modify("+$interval $period");

        //
        // We get the start date from the datetime object above and start day saved in the product metadata
        //
        $startYear = $oneIntervalAhead->format('Y');

        $startMonth = $oneIntervalAhead->format('m');

        return $oneIntervalAhead->setDate($startYear, $startMonth, $startDay)
                                ->getTimestamp();
    }

    protected function getProductPlanId($product, $order)
    {
        $productId = $product['product_id'];

        $metadata = get_post_meta($productId);

        list($planId, $created, $key) = $this->createOrGetPlanId($metadata, $product, $order);

        //
        // If new plan was created, we delete the old plan id
        // If we created a new planId, we have to store it as post metadata
        //
        if ($created === true)
        {
            delete_post_meta($productId, $key);

            add_post_meta($productId, $key, $planId, true);
        }

        return $planId;
    }

    /**
     * Takes in product metadata and product
     * Creates or gets created plan
     *
     * @param $metadata
     * @param $product
     * @param $order
     * @return array
     */
    protected function createOrGetPlanId($metadata, $product, $order)
    {
        list($key, $planArgs) = $this->getPlanArguments($product, $order);

        //
        // If razorpay_plan_id is set in the metadata,
        // we check if the amounts match and return the plan id
        //
        if (isset($metadata[$key]) === true)
        {
            $create = false;

            $planId = $metadata[$key][0];

            try
            {
                $plan = $this->api->plan->fetch($planId);
            }
            catch (Exception $e)
            {
                //
                // If plan id fetch causes an error, we re-create the plan
                //
                $create = true;
            }

            if (($create === false) and
                ($plan['item']['amount'] === $planArgs['item']['amount']))
            {
                return array($plan['id'], false, $key);
            }
        }

        //
        // By default we create a new plan
        // if metadata doesn't have plan id set
        //
        $planId = $this->createPlan($planArgs);

        return array($planId, true, $key);
    }

    protected function createPlan($planArgs)
    {
        try
        {
            $plan = $this->api->plan->create($planArgs);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_PLAN_CREATION_FAILED,
                400
            );
        }

        // Storing the plan id as product metadata, unique set to true
        return $plan['id'];
    }

    protected function getPlanArguments($product, $order)
    {
        $sub          = $this->getWooCommerceSubscriptionFromOrderId($order->get_id());

        $period       = $sub->get_billing_period();

        $interval     = $sub->get_billing_interval();

        $recurringFee = $sub->get_total();

        //
        // Ad-Hoc code
        //
        if ($period === 'year')
        {
            $period = 'month';

            $interval *= 12;
        }

        $planArgs = array(
            'period'   => $this->getProductPeriod($period),
            'interval' => $interval
        );

        // TODO: Should convert to INR if currency is USD
        $item = array(
            'name'     => $product['name'],
            'amount'   => (int) round($recurringFee * 100),
            'currency' => get_woocommerce_currency(),
        );

        if ($item['currency'] !== self::INR)
        {
            $this->razorpay->handleCurrencyConversion($item);
        }

        $planArgs['item'] = $item;

        return array($this->getKeyFromPlanArgs($planArgs), $planArgs);
    }

    private function getKeyFromPlanArgs(array $planArgs)
    {
        $item = $planArgs['item'];

        $hashInput = implode('|', [
            $item['amount'],
            $planArgs['period'],
            $planArgs['interval']
        ]);

        return self::RAZORPAY_PLAN_ID . sha1($hashInput);
    }

    // TODO: Take care of trial period here
    public function getDisplayAmount($order)
    {
        $product = $this->getProductFromOrder($order);

        $productId = $product['product_id'];

        $sub = $this->getWooCommerceSubscriptionFromOrderId($order->get_id());

        $recurringFee = $sub->get_total();

        $signUpFee = WC_Subscriptions_Product::get_sign_up_fee($productId);

        return $recurringFee + $signUpFee;
    }

    private function getProductPeriod($period)
    {
        $periodMap = array(
            'day'   => 'daily',
            'week'  => 'weekly',
            'month' => 'monthly',
            'year'  => 'yearly'
        );

        return $periodMap[$period];
    }

    protected function getCustomerId($order)
    {
        $data = $this->razorpay->getCustomerInfo($order);

        //
        // This line of code tells api that if a customer is already created,
        // return the created customer instead of throwing an exception
        // https://docs.razorpay.com/v1/page/customers-api
        //
        $data['fail_existing'] = '0';

        try
        {
            $customer = $this->api->customer->create($data);
        }
        catch (Exception $e)
        {
            $message = $e->getMessage();

            throw new Errors\Error(
                $message,
                WooErrors\SubscriptionErrorCode::API_CUSTOMER_CREATION_FAILED,
                400
            );
        }

        return $customer['id'];
    }

    public function getProductFromOrder($order)
    {
        $products = $order->get_items();

        $count = $order->get_item_count();

        //
        // Technically, subscriptions work only if there's one array in the cart
        //
        if ($count > 1)
        {
            throw new Exception('Currently Razorpay does not support more than'
                                . ' one product in the cart if one of the products'
                                . ' is a subscription.');
        }

        return array_values($products)[0];
    }

    protected function getSubscriptionSessionKey($orderId)
    {
        return self::RAZORPAY_SUBSCRIPTION_ID . $orderId;
    }
}
