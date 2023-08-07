<?php

namespace app\models\subscriptions;

use app\components\db\ActiveRecordWithTest;
use Yii;

/**
 * @property int $id
 * @property int $restaurant_id
 * @property int $promo_restaurant_id
 */
class SubscriptionRestaurantNearby extends ActiveRecordWithTest
{
    const RADIUS = 5000;
    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions_restaurants_nearby';
    }

    /**
     * @return \yii\db\Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return Yii::$app->get('db_resources_write');
    }
}