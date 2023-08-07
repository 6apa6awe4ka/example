<?php

namespace app\models\subscriptions\payments;

use app\components\db\ActiveRecordWithTest;
use app\components\payments\AgaingencyGateway;
use app\components\payments\PaymentGatewayInterface;
use app\components\payments\restcompanyGateway;
use app\models\subscriptions\Subscription;
use app\models\subscriptions\SubscriptionPrice;
use app\models\subscriptions\SubscriptionRestaurant;
use Yii;
use yii\helpers\BaseUrl;

/**
 * @property int $id
 * @property int $owner_id
 * @property int $gateway_code
 * @property string $gateway_payment_id
 * @property int $timestamp
 * @property ?int $timestamp_done
 * @property int $price
 * @property int $currency_code
 * @property int $status
 * @property int $type
 * @property string $customer_email
 *
 * @property PaymentSubscriptionInfo $info
 */
class Payment extends ActiveRecordWithTest
{
    const CURRENCY_CODE_USD = 1;
    const GATEWAY_CODE_RG = 0;
    const GATEWAY_CODE_AGAINGENCY = 1;
    const STATUS_NEW = 0;
    const STATUS_COMPLETED = 1;
    const STATUS_CANCELLED = 2;
    const STATUS_FAILED = 3;
    const STATUS_IN_PROGRESS = 4;
    const STATUS_INNER_CANCELLED = 5;
    const STATUS_NOT_FOUND = 6;
    const TYPE_SUBSCRIPTION = 0;

    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_payments';
    }

    public function getInfo()
    {
        return $this->hasOne(PaymentSubscriptionInfo::className(), ['payment_id' => 'id'])->orderBy(['id' => SORT_DESC]);
    }

    public static function getPaymentGateway(
        int $gateway_code
    )
    {
        switch ($gateway_code) {
            case static::GATEWAY_CODE_RG:
                return new restcompanyGateway();
                break;
            case static::GATEWAY_CODE_AGAINGENCY:
                return new AgaingencyGateway();
        }
    }

    public static function placeActivationOrder(
        int $payment_gateway_code,
        int $owner_id,
        int $inner_code,
        int $price_id,
        int $restaurant_id,
        string $customer_email
    )
    {
        /** @var ?SubscriptionRestaurant $subscription_restaurant */
        [$validation, $subscription_restaurant, $restaurant] = static::validationOnActivation(
            $owner_id,
            $inner_code,
            $restaurant_id
        );

        if ($validation === false) {
            return [null, null, null];
        }

        $is_restaurant_restcompany_gateway = ($payment_gateway_code === Payment::GATEWAY_CODE_RG);

        /** @var PaymentGatewayInterface $payment_gateway */
        $payment_gateway = static::getPaymentGateway(
            $payment_gateway_code
        );

        if ($subscription_restaurant === null) {
            $subscription = new Subscription();
            $subscription->owner_id = $owner_id;
            $subscription->inner_code = $inner_code;
            $subscription->stop_timestamp = 0;
            $subscription->status = Subscription::STATUS_INACTIVE;
            $subscription->save();

            $subscription_restaurant = new SubscriptionRestaurant();
            $subscription_restaurant->owner_id = $owner_id;
            $subscription_restaurant->inner_code = $inner_code;
            $subscription_restaurant->subscription_id = $subscription->id;
            $subscription_restaurant->restaurant_id = $restaurant_id;
            $subscription_restaurant->country_id = $restaurant->country_id;
            $subscription_restaurant->city_id = $restaurant->city->id ?? 0;
            $subscription_restaurant->stop_timestamp = 0;
            $subscription_restaurant->status = SubscriptionRestaurant::STATUS_INACTIVE;
            $subscription_restaurant->save();

            $subscription->refresh();
        } else {
            [$payment_validation, $redirect_link, $inner_payment_id] = static::validation(
                $subscription_restaurant->subscription_id
            );

            if (!$payment_validation && !$redirect_link) {
                return [null, null, null];
            }

            $subscription = Subscription::findOne([
                'id' => $subscription_restaurant->subscription_id,
            ]);

            if ($redirect_link) {
                return [$subscription, $redirect_link, $inner_payment_id];
            }

            $subscription->inner_code = $inner_code;
            $subscription_restaurant->inner_code = $inner_code;
        }

        $subscription->gateway_code = $payment_gateway_code;

        $price_model = SubscriptionPrice::getPrice(
            $price_id,
            $inner_code
        );
        $duration = $price_model->duration;
        $price = $is_restaurant_restcompany_gateway ? 0 : $price_model->price;

        [$gateway_payment_id, $payment_status, $payment_link] = $payment_gateway->subscriptionPayment(
            $price,
            $customer_email,
            $subscription
        );

        $inner_payment = new static();
        $inner_payment->owner_id = $owner_id;
        $inner_payment->gateway_code = $payment_gateway_code;
        $inner_payment->gateway_payment_id = $gateway_payment_id;
        $inner_payment->timestamp = time();
        $inner_payment->price = $price;
        $inner_payment->currency_code = static::CURRENCY_CODE_USD;
        $inner_payment->status = $payment_status;
        $inner_payment->type = static::TYPE_SUBSCRIPTION;
        $inner_payment->customer_email = $customer_email;
        $inner_payment->save();

        $payment_info = new PaymentSubscriptionInfo();
        $payment_info->payment_id = $inner_payment->id;
        $payment_info->subscription_id = $subscription->id;
        $payment_info->price_id = $price_model->id;
        $payment_info->price = $price;
        $payment_info->type = PaymentSubscriptionInfo::TYPE_ACTIVATION;
        $payment_info->save();

        $subscription->save();

        return [$subscription, $payment_link, $inner_payment];
    }

    public static function placeUpgradeOrder(
        int $payment_gateway_code,
        int $owner_id,
        int $inner_code,
        int $price_id,
        int $upgradable_restaurant_id,
        string $customer_email
    )
    {
        /** @var Subscription $upgradable_subscription */
        [$validation, $upgradable_subscription] = static::validationOnUpgrade(
            $owner_id,
            $inner_code,
            $upgradable_restaurant_id
        );

        if ($validation === false) {
            return [null, null, null];
        }

        [$payment_validation, $redirect_link, $inner_payment_id] = static::validation(
            $upgradable_subscription->id
        );

        if (!$payment_validation) {
            if ($redirect_link) {
                return [$upgradable_subscription, $redirect_link, $inner_payment_id];
            } else {
                return [null, null, null];
            }
        }

        /** @var PaymentGatewayInterface $payment_gateway */
        $payment_gateway = static::getPaymentGateway(
            $payment_gateway_code
        );

        [$price, $duration,,,,$price_model, $prev_price_model] = SubscriptionPrice::getUpgradePriceData(
            $upgradable_subscription,
            $price_id,
            $inner_code
        );

        if ($payment_gateway)  {
            [$gateway_payment_id, $payment_status, $payment_link] = $payment_gateway->subscriptionPayment(
                $price,
                $customer_email,
                $upgradable_subscription
            );

            $payment_info = new PaymentSubscriptionInfo();
            $payment_info->subscription_id = $upgradable_subscription->id;
            $payment_info->price_id = $price_model->id;
            $payment_info->price = $price;
            $payment_info->type = PaymentSubscriptionInfo::TYPE_UPGRADE;
            $payment_info->save();

            $inner_payment = new static();
            $inner_payment->owner_id = $owner_id;
            $inner_payment->gateway_code = $payment_gateway_code;
            $inner_payment->gateway_payment_id = $gateway_payment_id;
            $inner_payment->timestamp = time();
            $inner_payment->price = $price;
            $inner_payment->currency_code = static::CURRENCY_CODE_USD;
            $inner_payment->status = $payment_status;
            $inner_payment->type = static::TYPE_SUBSCRIPTION;
            $inner_payment->payment_info_id = $payment_info->id;
            $inner_payment->customer_email = $customer_email;
            $inner_payment->save();
        } else {
            $upgradable_subscription->inner_code = $inner_code;
            $upgradable_subscription->gateway_code = $payment_gateway_code;
            $upgradable_subscription->stop_timestamp = strtotime($duration, time());
            $upgradable_restaurant = SubscriptionRestaurant::findOne([
                'subscription_id' => $upgradable_subscription->id,
            ]);
            $upgradable_restaurant->inner_code = $inner_code;
            $upgradable_restaurant->stop_timestamp = $upgradable_subscription->stop_timestamp;
            $upgradable_restaurant->save();
            $upgradable_subscription->save();
        }

        return [$upgradable_subscription, $payment_link, $inner_payment->id];
    }

    public static function validationOnActivation(
        int $owner_id,
        int $inner_code,
        int $restaurant_id
    )
    {
        [$validation, $subscription_restaurant, $restaurant] = SubscriptionRestaurant::validation(
            $owner_id,
            $restaurant_id,
            $inner_code
        );

        return [$validation, $subscription_restaurant, $restaurant];
    }

    public static function validationOnUpgrade(
        int $owner_id,
        int $inner_code,
        int $upgradable_restaurant_id
    )
    {
        $validation = false;
        $upgradable_subscription = null;

        [$inner_code_validation, $upgradable_inner_codes] = Subscription::validationUpgradeInnerCode(
            $inner_code
        );

        if (!$inner_code_validation) {
            return [false, null];
        }

        /** @var SubscriptionRestaurant $upgradable_restaurant */
        $upgradable_restaurant = SubscriptionRestaurant::findOne([
            'restaurant_id' => $upgradable_restaurant_id,
        ]);

        $upgradable_subscription = Subscription::find()
            ->where([
                'id' => $upgradable_restaurant->subscription_id,
                'owner_id' => $owner_id,
                'inner_code' => $upgradable_inner_codes,
            ])
            ->andWhere(
                ['>', 'stop_timestamp', time()]
            )
            ->one();

        if ($upgradable_subscription) {
            return [true, $upgradable_subscription];
        }

        return [false, null];
    }

    public static function validation(
        int $subscription_id
    )
    {
        #TODO: не обработан случай STATUS_IN_PROGRESS, но наверное и не понадобится
        /** @var PaymentSubscriptionInfo $payment_info */
        $payment_table = Payment::pureTableName();
        $payment_info = PaymentSubscriptionInfo::find()
            ->joinWith('payment')
            ->where([
                'subscription_id' => $subscription_id,
                "{$payment_table}.timestamp_done" => null,
            ])
            ->orderBy('id DESC')
            ->one();

        if (!$payment_info) {
            return [true, null, null];
        }

        $payment_info->payment->resolvePayment();

        switch ($payment_info->payment->status) {
            case static::STATUS_NEW:
            #TODO: в againgency STATUS_IN_PROGRESS спорный статус, охватывает как ввод данных пользователем, так и их обработку
            case static::STATUS_IN_PROGRESS:
                $payment_info->payment->timestamp_done = time();
                $payment_info->payment->status = static::STATUS_INNER_CANCELLED;
                $payment_info->payment->save();
                return [true, null, null];
            case static::STATUS_CANCELLED:
            case static::STATUS_FAILED:
                return [true, null, null];
            case static::STATUS_COMPLETED:
                $subscription_url = BaseUrl::to([
                    'subscriptions/subscription',
                    'subscription_id' => $subscription_id,
                ]);
                return [false, $subscription_url, null];
        }
    }

    public function resolvePayment(
        ?PaymentGatewayInterface $gateway = null
    )
    {
        $payment_link = null;

        if (empty($this->timestamp_done)) {
            switch ($this->status) {
                case static::STATUS_NEW:
                case static::STATUS_IN_PROGRESS:
                    $gateway = $gateway ?? Payment::getPaymentGateway(
                        $this->gateway_code
                    );
                    [$status, $payment_link] = $gateway->checkPayment($this);

                    if ($status === null) {
                        $this->status = static::STATUS_NOT_FOUND;
                        $this->timestamp_done = time();
                        $this->save();
                        return;
                    }

                    $this->status = $status;
                    if (!in_array($status, [
                        static::STATUS_NEW,
                        static::STATUS_IN_PROGRESS
                    ])) {
                        $this->resolvePayment();
                    }
                    break;
                case static::STATUS_FAILED:
                case static::STATUS_CANCELLED:
                    $this->info->subscription->gateway_code = null;
                    $this->info->subscription->save();

                    $template = 'subscriptions/renewal/unsuccess';
                    $subject = 'Your restcompany subscription is CANCELED!';
                    $mail_data = [
                        'subscription' => $this->info->subscription,
                    ];
                    $this->timestamp_done = time();

                    try {
                        Yii::$app->mailer_premium->htmlLayout = 'layouts/subscriptions.php';
                        Yii::$app->mailer_premium->compose(
                            $template,
                            $mail_data
                        )
                            ->setFrom(['premium@restcompany.com' => Yii::$app->name])
                            ->setTo($this->customer_email)
                            ->setSubject($subject)
                            ->send();
                    } catch (\Exception $exception) {

                    }
                    break;
                case static::STATUS_COMPLETED:
                    $this->info->subscription->applyPayment($this);
                    break;
            }
            $this->save();
        }

        return $payment_link;
    }
}
