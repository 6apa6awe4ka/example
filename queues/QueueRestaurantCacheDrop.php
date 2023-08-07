<?php

namespace app\models\subscriptions\queues;

use app\components\db\ActiveRecordWithTest;
use app\models\frontend\RestaurantCity;

/**
 * @property int $id
 * @property int $restaurant_id
 * @property int counter
 * @property bool $city_dropped
 * @property bool $set_dropped
 * @property bool $meals_dropped
 */
class QueueRestaurantCacheDrop extends ActiveRecordWithTest
{
    const COUNTER_MAX = 10;
    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'queue_subscriptions_restaurants_caches_drop';
    }

    public static function add(
        int $restaurant_id
    )
    {
        /** @var static $model */
        $model = new static();
        $model->restaurant_id = $restaurant_id;
        $model->save();
    }

    public function complete()
    {
        $restaurant_city = RestaurantCity::findOne([
            'restaurant_id' => $this->restaurant_id
        ]);

        $full_success = true;

        static::dropCall(
            "https://restcompany.com/ajax/fake-slug/clear-city-cache?ids={$restaurant_city->city_id}",
            'city_dropped',
            $full_success
        );

        static::dropCall(
            "https://restcompany.com/ajax/fake-slug/clear-city-set-cache?ids={$restaurant_city->city_id}",
            'set_dropped',
            $full_success
        );

        static::dropCall(
            "https://restcompany.com/ajax/clear-restaurant-meals-cache?ids={$this->restaurant_id}",
            'meals_dropped',
            $full_success
        );

        if ($full_success) {
            $this->delete();
        } else {
            $this->counter++;
            $this->save();
        }
    }

    protected function dropCall(
        string $url,
        string $attr,
        &$full_success
    )
    {
        if (!$this->$attr) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_exec($ch);
            $response_info = curl_getinfo($ch);

            $success = ($response_info['http_code'] === 200);

            if ($success) {
                $this->$attr = true;
            } else {
                $full_success = false;
            }
        }
    }
}
