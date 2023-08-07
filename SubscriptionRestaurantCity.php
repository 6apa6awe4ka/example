<?php

namespace app\models\subscriptions;

use app\components\db\ActiveRecordWithTest;

/**
 * @property int $id
 * @property int $restaurant_id
 * @property int $city_id
 */
class SubscriptionRestaurantCity extends ActiveRecordWithTest
{
    protected static function baseTableName()
    {
        return 'owners_subscriptions_restaurants_cities';
    }

    /**
     * @return \yii\db\Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return \Yii::$app->get('db_resources_write');
    }
}
