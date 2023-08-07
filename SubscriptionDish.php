<?php

namespace app\models\subscriptions;

use app\components\db\ActiveRecordWithTest;
use app\models\subscriptions\payments\PaymentSubscriptionInfo;
use app\models\User;
use Yii;

/**
 * @property int $id
 * @property int $subscription_id
 * @property int $restaurant_id
 * @property string $name
 * @property string $image_path
 * @property string $thumbnail_path
 * @property int $i
 * @property ?boolean status
 * @property float $price
 */
class SubscriptionDish extends ActiveRecordWithTest
{
    const PER_RESTAURANT_MAX_COUNT = 5;
    const STATUS_APPROVED = 1;
    const STATUS_REJECTED = 0;
    const STATUS_ON_MODERATION = null;

    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions_dishes';
    }

    /**
     * @return \yii\db\Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return Yii::$app->get('db_resources_write');
    }

    public static function queryRestaurantDishes(
        int $restaurant_id
    )
    {
        return static::find()
            ->where([
                'restaurant_id' => $restaurant_id,
            ])
            ->orderBy('i ASC')
            ->limit(static::PER_RESTAURANT_MAX_COUNT)
        ;
    }

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes); // TODO: Change the autogenerated stub

        /** @var Subscription $subscription */
        $subscription = Subscription::find()
            ->where([
                'id' => $this->subscription_id,
            ])->andWhere(
                ['>', 'stop_timestamp', time()]
            )
            ->one();

        if ($subscription) {
            $subscription_restaurant = SubscriptionRestaurant::find()
                ->where([
                    'subscription_id' => $this->subscription_id,
                    'restaurant_id' => $this->restaurant_id,
                ])
                ->one();

            $restaurant_dishes = static::find()
                ->where([
                    'subscription_id' => $this->subscription_id,
                    'restaurant_id' => $this->restaurant_id,
                ])
                ->andWhere(
                    ['not', ['status' => static::STATUS_ON_MODERATION]]
                )
                ->orderBy('status DESC')
                ->limit(static::PER_RESTAURANT_MAX_COUNT)
                ->all();

            if (count($restaurant_dishes) === static::PER_RESTAURANT_MAX_COUNT) {
                $promo_dishes = true;
                foreach ($restaurant_dishes as $dish) {
                    if ($dish->status !== static::STATUS_APPROVED) {
                        $promo_dishes = false;
                        break;
                    }
                }

                if ($subscription_restaurant->promo_dishes !== $promo_dishes) {
                    $subscription_restaurant->promo_dishes = $promo_dishes;
                    $subscription_restaurant->save();

                    /** @var PaymentSubscriptionInfo $last_payment_info */
                    $last_payment_info = PaymentSubscriptionInfo::find()
                        ->with('payment')
                        ->where([
                            'subscription_id' => $subscription->id,
                        ])
                        ->orderBy('id DESC')
                        ->one();

                    $customer = User::findOne([
                        'id' => $subscription->owner_id,
                    ]);

                    $customer_email = $last_payment_info ?
                        $last_payment_info->payment->customer_email :
                        Yii::$app->user->identity->email;

                    if ($customer_email) {
                        if ($subscription_restaurant->promo_dishes) {
                            $template = 'subscriptions/dishes_success_moderation';
                            $subject = 'Your photos have successfully passed moderation! You are already advertised!';
                        } else {
                            $template = 'subscriptions/dishes_unsuccess_moderation';
                            $subject = 'Your photos have not passed moderation, please change them';
                        }
                        $mail_data = [
                            'subscription' => $subscription,
                            'customer' => $customer,
                        ];

                        try {
                            Yii::$app->mailer_premium->htmlLayout = 'layouts/subscriptions.php';
                            Yii::$app->mailer_premium->compose(
                                $template,
                                $mail_data
                            )
                                ->setFrom(['premium@restcompany.com' => Yii::$app->name])
                                ->setTo($customer_email)
                                ->setSubject($subject)
                                ->send();
                        } catch (\Exception $exception) {

                        }
                    }
                }
            } elseif ($subscription_restaurant->promo_dishes) {
                $subscription_restaurant->promo_dishes = false;
                $subscription_restaurant->save();
            }
        }
    }
}