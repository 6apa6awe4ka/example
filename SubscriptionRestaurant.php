<?php

namespace app\models\subscriptions;

use app\components\db\ActiveRecordWithTest;
use app\models\frontend\Restaurant;
use app\models\frontend\RestaurantCity;
use app\models\frontend\RestaurantIsland;
use app\models\RestaurantsOwners;
use app\models\subscriptions\queues\QueueNearbyRestaurants;
use app\models\subscriptions\queues\QueueNearbyRestaurantsCachesDrop;
use app\models\subscriptions\queues\QueueRestaurantCacheDrop;
use app\models\User;

/**
 * @property int $id
 * @property int $owner_id
 * @property int $inner_code
 * @property int $subscription_id
 * @property int $restaurant_id
 * @property int $stop_timestamp
 * @property int $country_id
 * @property int $city_id
 * @property boolean $promo_dishes
 * @property ?int $island_id
 * @property int $status
 */
class SubscriptionRestaurant extends ActiveRecordWithTest
{
    const STATUS_INACTIVE = Subscription::STATUS_INACTIVE;
    const STATUS_ACTIVE = Subscription::STATUS_ACTIVE;

    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions_restaurants';
    }

    /**
     * @return \yii\db\Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return \Yii::$app->get('db_resources_write');
    }

    public static function querySubscriptionRestaurants(
        int $subscription_id
    )
    {
        return static::find()->where([
            'subscription_id' => $subscription_id,
        ])->andWhere(
            ['>', 'stop_timestamp', time()]
        );
    }

    /**
     * @param int $owner_id
     * @param int $restaurant_id
     * @param int $inner_code
     * @return array
     */
    public static function validation(
        int $owner_id,
        int $restaurant_id,
        int $inner_code
    )
    {
        [$inner_code_validation, $concurrent_inner_codes] = Subscription::validationInnerCode(
            $inner_code
        );

        if (!$inner_code_validation) {
            return [false, null, null];
        }

        /** @var static $subscription_restaurant */
        $subscription_restaurant = static::find()
            ->where([
                'restaurant_id' => $restaurant_id,
                'owner_id' => $owner_id,
            ])
            ->andWhere(
                ['in', 'inner_code', $concurrent_inner_codes]
            )
            ->one();

        if ($subscription_restaurant) {
            if (
                $subscription_restaurant->inner_code === $inner_code ||
                $subscription_restaurant->status === static::STATUS_INACTIVE
            ) {
                return [true, $subscription_restaurant, null];
            } else {
                return [false, null, null];
            }
        }

        $query_restaurant = Restaurant::find()
            ->with('city')
            ->where([
                'id' => $restaurant_id,
            ])
            ->andWhere(['<', 'is_closed', 1]);

//        if (!in_array($restaurant_id, Subscription::FORCED_RESTAURANTS)) {
//            $query_restaurant->andWhere([
//                'country_id' => 37,
//            ]);
//        }

        $restaurant = $query_restaurant->one();

        #TODO: разобраться с маппингом (COUNTRY_ID_USA)
        $validation = $restaurant && RestaurantsOwners::find()
            ->leftJoin('users', 'users.id = restaurants_owners.user_id')
            ->where([
                'restaurants_owners.restaurant_id' => $restaurant_id,
                'users.is_active' => User::STATUS_ACTIVE,
                'users.id' => $owner_id,
            ])
            ->one();

        return [$validation, null, $restaurant];
    }

    public function beforeSave($insert)
    {
        if ($insert) {
            $restaurant_cities = RestaurantCity::find()
                ->where([
                    'restaurant_id' => $this->restaurant_id,
                ])
                ->all();

            /** @var RestaurantCity $restaurant_city */
            foreach ($restaurant_cities as $restaurant_city) {
                $subscription_restaurant_city = new SubscriptionRestaurantCity();
                $subscription_restaurant_city->restaurant_id = $this->restaurant_id;
                $subscription_restaurant_city->city_id = $restaurant_city->city_id;
                $subscription_restaurant_city->save();
            }

            /** @var RestaurantIsland $restaurant_island */
            $restaurant_island = RestaurantIsland::find()
                ->where([
                    'restaurant_id' => $this->restaurant_id,
                ])
                ->one();

            if ($restaurant_island) {
                $this->island_id = $restaurant_island->island_id;
            }
        }

        return parent::beforeSave($insert); // TODO: Change the autogenerated stub
    }

    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        if ($this->inner_code === Subscription::INNER_CODE_PREMIUM) {
            if (
                $insert ||
                array_key_exists('inner_code', $changedAttributes)
            ) {
                QueueNearbyRestaurants::add(
                    $this->restaurant_id
                );
            }
        }

        if (
            $this->status === static::STATUS_ACTIVE && (
                $insert ||
                array_key_exists('inner_code', $changedAttributes)
            ) ||
            !$insert && array_key_exists('status', $changedAttributes)
        ) {
            QueueRestaurantCacheDrop::add(
                $this->restaurant_id
            );
        }

        if (
            !$insert && array_key_exists('promo_dishes', $changedAttributes)
        ) {
            QueueNearbyRestaurantsCachesDrop::add(
                $this->restaurant_id
            );
        }
    }

    public function afterDelete()
    {
        parent::afterDelete();

        QueueRestaurantCacheDrop::add(
            $this->restaurant_id
        );

        QueueNearbyRestaurantsCachesDrop::add(
            $this->restaurant_id
        );
    }
}
