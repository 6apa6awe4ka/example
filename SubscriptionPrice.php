<?php

namespace app\models\subscriptions;

use app\components\db\ActiveRecordWithTest;
use app\models\subscriptions\payments\Payment;
use app\models\subscriptions\payments\PaymentSubscriptionInfo;

/**
 * @property int $id
 * @property int $inner_code
 * @property int $price
 * @property string $duration
 */
class SubscriptionPrice extends ActiveRecordWithTest
{
    const ID_LIGHT_1_WEEK = 1;
    const ID_PREMIUM_1_MONTH = 2;
    const ID_PREMIUM_2_WEEKS = 3;

    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions_prices';
    }

    public static function getPriceView(
        int $price
    )
    {
        if ($price % 100) {
            return number_format($price / 100, 2);
        } else {
            return $price / 100;
        }
    }

    public static function getPrice(
        int $id,
        int $inner_code
    ): self
    {
        $price_model = static::find()
            ->where([
                'id' => $id,
                'inner_code' => $inner_code,
            ])
            ->one();

        return $price_model;
    }

    public static function getActualPrice(
        self $price_model
    )
    {
        $actual_price_model = static::find()
            ->where([
                'inner_code' => $price_model->inner_code,
                'duration' => $price_model->duration,
            ])
            ->one();

        return $actual_price_model;
    }

    public static function getUpgradePriceData(
        Subscription $subscription,
        int $price_id,
        int $inner_code
    )
    {
        #TODO: вписать валидацию кодов из Subscription
        if ($subscription->inner_code === Subscription::INNER_CODE_LIGHT) {
            if ($inner_code === Subscription::INNER_CODE_PREMIUM) {
                $price_model = static::getPrice(
                    $price_id,
                    Subscription::INNER_CODE_PREMIUM
                );
                $full_price = $price_model->price;

                #TODO: учесть множественно применённые платежи
                $payment_table = Payment::pureTableName();
                /** @var PaymentSubscriptionInfo $payment_info */
                $payment_info = PaymentSubscriptionInfo::find()
                    ->with('pricemodel')
                    ->joinWith('payment')
                    ->where([
                        'subscription_id' => $subscription->id,
                        "{$payment_table}.status" => Payment::STATUS_COMPLETED,
                    ])
                    ->orderBy('id DESC')
                    ->one();

                if ($payment_info->payment->gateway_code === Payment::GATEWAY_CODE_RG) {
                    $price = $full_price;
                    $unused_days = 0;
                    $unused_price = 0;
                } else {
                    [$price, $unused_days, $unused_price] = static::calcUpgradePriceInfoBase(
                        $payment_info->pricemodel->duration,
                        $subscription->stop_timestamp,
                        $payment_info->price,
                        $full_price
                    );
                }
            }
        }

        return [
            $price,
            $price_model->duration,
            $full_price,
            $unused_price,
            $unused_days,
            $price_model,
            $payment_info->pricemodel ?? null
        ];
    }

    public static function calcUpgradePriceInfoBase(
        string $duration,
        int $stop_timestamp,
        int $price_from,
        int $price_to
    )
    {
        $seconds_day = 24 * 60 * 60;
        $current_timestamp = time();
        $start_timestamp = strtotime("-{$duration}", $stop_timestamp);

        $full_days = (int)ceil(($stop_timestamp - $start_timestamp) / $seconds_day);
        $unused_days = (int)floor(($stop_timestamp - $current_timestamp) / $seconds_day);
        $unused_price = (int)(($unused_days / $full_days) * $price_from);
        $price = $price_to - $unused_price;
        return [$price, $unused_days, $unused_price];
    }
}
