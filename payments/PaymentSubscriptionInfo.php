<?php

namespace app\models\subscriptions\payments;

use app\components\db\ActiveRecordWithTest;
use app\models\subscriptions\Subscription;
use app\models\subscriptions\SubscriptionPrice;

/**
 * @property int $id
 * @property int $payment_id
 * @property int $subscription_id
 * @property int $price_id
 * @property int $price
 * @property int $type
 *
 * @property Subscription $subscription
 * @property SubscriptionPrice $pricemodel
 * @property Payment $payment
 */
class PaymentSubscriptionInfo extends ActiveRecordWithTest
{
    const TYPE_ACTIVATION = 0;
    const TYPE_RENEW = 1;
    const TYPE_UPGRADE = 2;

    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_payments_subscriptions_info';
    }

    public function getSubscription()
    {
        return $this->hasOne(Subscription::className(), ['id' => 'subscription_id']);
    }

    public function getPricemodel()
    {
        return $this->hasOne(SubscriptionPrice::className(), ['id' => 'price_id']);
    }

    public function getPayment()
    {
        return $this->hasOne(Payment::className(), ['id' => 'payment_id']);
    }
}
