<?php

namespace app\models\subscriptions;

use app\components\db\ActiveRecordWithTest;
use app\models\frontend\Restaurant;
use app\models\RestaurantsOwners;
use app\models\subscriptions\payments\Payment;
use app\models\subscriptions\payments\PaymentSubscriptionInfo;
use Yii;

/**
 * @property int $id
 * @property int $owner_id
 * @property int $inner_code
 * @property int $stop_timestamp
 * @property int $gateway_code
 * @property int $status
 *
 * @property SubscriptionRestaurant $restaurant
 */
class Subscription extends ActiveRecordWithTest
{
    const INNER_CODE_LIGHT = 1;
    const INNER_CODE_PREMIUM = 2;

    const FORCED_RESTAURANTS = [87521716];
    const STATUS_INACTIVE = 0;
    const STATUS_ACTIVE = 1;

    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions';
    }

    public function getRestaurant()
    {
        return $this->hasOne(SubscriptionRestaurant::className(), ['subscription_id' => 'id']);
    }

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if (
            array_key_exists('status', $changedAttributes) ||
            array_key_exists('stop_timestamp', $changedAttributes) ||
            array_key_exists('inner_code', $changedAttributes)
        ) {
            if ($this->restaurant) {
                $this->restaurant->status = $this->status;
                $this->restaurant->stop_timestamp = $this->stop_timestamp;
                $this->restaurant->inner_code = $this->inner_code;
                $this->restaurant->save();
            }
        }
    }

    /**
     * @param int $owner_id
     * @return \yii\db\ActiveQuery
     */
    public static function queryOwnerSubscriptions(
        int $owner_id
    )
    {
        return static::find()->where([
            'owner_id' => $owner_id,
        ])->andWhere(
            ['>', 'stop_timestamp', time()]
        );
    }

    public static function queryOwnerSubscription(
        int $subscription_id,
        int $owner_id,
        int $subscription_code = 0
    )
    {
        $query = Subscription::queryOwnerSubscriptions(
            $owner_id
        )->andWhere([
            'id' => $subscription_id
        ]);

        if ($subscription_code > 0) {
            $query->andWhere([
                'inner_code' => $subscription_code,
            ]);
        }

        return $query;
    }

    /**
     * only USA
     * @param int $owner_id
     * @return bool
     */
    public static function checkOwner(
        int $owner_id
    ): bool
    {
        $ids = RestaurantsOwners::find()
            ->select('restaurant_id')
            ->where([
                'user_id' => $owner_id,
            ])
            ->column();

        if (!$ids) {
            return false;
        } elseif (array_intersect($ids, static::FORCED_RESTAURANTS)) {
            return true;
        }

        return Restaurant::find()
            ->where(['in', 'id', $ids])
//            ->andWhere([
//                'country_id' => 37
//            ])
            ->exists();
    }

    public static function validationInnerCode(
        int $inner_code
    )
    {
        $inner_codes = [
            self::INNER_CODE_LIGHT,
            self::INNER_CODE_PREMIUM,
        ];

        switch ($inner_code) {
            case static::INNER_CODE_LIGHT:
            case static::INNER_CODE_PREMIUM:
                return [true, $inner_codes];
        }
        return [false, null];
    }

    public static function validationUpgradeInnerCode(
        int $inner_code
    )
    {
        switch ($inner_code) {
            case static::INNER_CODE_PREMIUM:
                $upgradable_codes = [
                    static::INNER_CODE_LIGHT
                ];
                return [true, $upgradable_codes];
        }

        return [false, null];
    }

    public function applyPayment(
        Payment $payment
    )
    {
        if ($payment->info->type === PaymentSubscriptionInfo::TYPE_UPGRADE) {
            $this->inner_code = $payment->info->pricemodel->inner_code;
            $time = time();
        } else {
            $time = $this->stop_timestamp > time() ? $this->stop_timestamp : time();
        }

        $applied_time = strtotime($payment->info->pricemodel->duration, $time);
        $this->status = static::STATUS_ACTIVE;
        $this->stop_timestamp = $applied_time;
        $this->save();

        $payment->timestamp_done = time();
        $payment->save();

        if ($payment->gateway_code > Payment::GATEWAY_CODE_RG) {
            $restaurant = Restaurant::findOne([
                'id' => $this->restaurant->restaurant_id,
            ]);

            $mail_data = [
                'subscription' => $this,
                'restaurant' => $restaurant,
            ];

            switch ($payment->info->type) {
                case PaymentSubscriptionInfo::TYPE_ACTIVATION:
                case PaymentSubscriptionInfo::TYPE_UPGRADE:
                    switch ($this->inner_code) {
                        case static::INNER_CODE_LIGHT:
                            $path_name = 'light';
                            $subject = 'Payment for the restcompany Light subscription was successful!';
                            break;
                        case static::INNER_CODE_PREMIUM:
                            $path_name = 'premium';
                            $subject = 'Payment for the restcompany Premium subscription was successful!';
                            break;
                    }
                    $template = "subscriptions/activation/success/{$path_name}";
                    break;
                case PaymentSubscriptionInfo::TYPE_RENEW:
                    $template = 'subscriptions/renewal/success';
                    $subject = 'Your restcompany subscription has been successfully renewed.';
                    break;
            }

            try {
                Yii::$app->mailer_premium->htmlLayout = 'layouts/subscriptions.php';
                Yii::$app->mailer_premium->compose(
                    $template,
                    $mail_data
                )
                    ->setFrom(['premium@restcompany.com' => Yii::$app->name])
                    ->setTo($payment->customer_email)
                    ->setSubject($subject)
                    ->send();

                $auth_notificated = OwnerAuthNotifications::find()
                    ->where([
                        'owner_id' => $this->owner_id,
                    ])
                    ->exists();

                if (!$auth_notificated) {
                    Yii::$app->mailer_premium->compose(
                        'subscriptions/auth',
                        []
                    )
                        ->setFrom(['premium@restcompany.com' => Yii::$app->name])
                        ->setTo($payment->customer_email)
                        ->setSubject('Your login details for the restcompany personal account – Please change your password!')
                        ->send();

                    OwnerAuthNotifications::add(
                        $this->owner_id
                    );
                }
            } catch (\Exception $exception) {

            }
        }
    }

    public static function unsubscribe(
        int $owner_id,
        int $subscription_id
    )
    {
        $success = false;
        $subscription = null;

        /** @var Subscription $subscription */
        $subscription = static::find()
            ->where([
                'id' => $subscription_id,
                'owner_id' => $owner_id,
            ])
            ->andWhere(['not', ['gateway_code' => null]])
            ->andWhere(['>', 'stop_timestamp', time()])
            ->one();

        if (!$subscription) {
            return [false, null];
        }

        if ($subscription->gateway_code === null) {
            return [false, null];
        }

        if (in_array($subscription->gateway_code, [
            Payment::GATEWAY_CODE_AGAINGENCY,
            Payment::GATEWAY_CODE_RG
        ])) {
            $success = true;
        } else {
//            $payment_gateway = Payment::getPaymentGateway(
//                $subscription->gateway_code
//            );
//            $success = $payment_gateway->unsubscribe();
        }

        if ($success) {
            $subscription->gateway_code = null;
            $subscription->save();

            /** @var PaymentSubscriptionInfo $last_payment_info */
            $last_payment_info = PaymentSubscriptionInfo::find()
                ->with('payment')
                ->where([
                    'subscription_id' => $subscription->id,
                ])
                ->orderBy('id DESC')
                ->one();

            $template = 'subscriptions/unsubscribe';
            $subject = 'You have successfully cancelled the restcompany subscription!';
            $mail_data = [
                'subscription' => $subscription,
            ];
            try {
                Yii::$app->mailer_premium->htmlLayout = 'layouts/subscriptions.php';
                Yii::$app->mailer_premium->compose(
                    $template,
                    $mail_data
                )
                    ->setFrom(['premium@restcompany.com' => Yii::$app->name])
                    ->setTo($last_payment_info->payment->customer_email)
                    ->setSubject($subject)
                    ->send();
            } catch (\Exception $exception) {

            }
        }

        return [$success, $subscription];
    }
}
