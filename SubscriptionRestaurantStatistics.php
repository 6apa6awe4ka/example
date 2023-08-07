<?php

namespace app\models\subscriptions;

use app\components\db\ActiveRecordWithTest;
use Yii;

/**
 * @property int $daystamp
 * @property int $promo_code
 * @property int $restaurant_id
 * @property int $top_shows
 * @property int $top_clicks
 * @property int $dishes_shows
 * @property int $dishes_clicks
 * @property int $delivery_clicks
 * @property int $phone_clicks
 * @property int $menu_clicks
 * @property int $reservation_clicks
 */
class SubscriptionRestaurantStatistics extends ActiveRecordWithTest
{
    const FIELDS = [
        'top_shows',
        'top_clicks',
        'dishes_shows',
        'dishes_clicks',
        'delivery_clicks',
        'phone_clicks',
        'menu_clicks',
        'reservation_clicks',
    ];

    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions_restaurants_statistics';
    }

    /**
     * @return \yii\db\Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return Yii::$app->get('db_userdata');
    }
}
