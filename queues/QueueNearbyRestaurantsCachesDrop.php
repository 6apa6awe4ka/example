<?php

namespace app\models\subscriptions\queues;

use app\components\db\ActiveRecordWithTest;
use app\models\subscriptions\SubscriptionRestaurantNearby;

/**
 * @property int $id
 * @property int $promo_restaurant_id
 * @property int $counter
 */
class QueueNearbyRestaurantsCachesDrop extends ActiveRecordWithTest
{
    const COUNTER_MAX = 5;

    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'queue_subscriptions_restaurants_nearby_caches_drop';
    }

    public static function add(
        int $promo_restaurant_id
    )
    {
        /** @var static $model */
        $model = new static();
        $model->promo_restaurant_id = $promo_restaurant_id;
        $model->save();
    }

    public function complete()
    {
        $full_success = true;
        $limit = 1000;
        $offset = 0;

        while (true) {
            $ids = SubscriptionRestaurantNearby::find()
                ->select(['restaurant_id'])
                ->andWhere([
                    'promo_restaurant_id' => $this->promo_restaurant_id,
                ])
                ->limit($limit)
                ->offset($offset)
                ->column();

            if (!$ids) {
                break;
            }

            $offset += $limit;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_USERAGENT, "rg mapping");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['ids' => implode(',', $ids)]);
            curl_setopt($ch, CURLOPT_URL, 'https://restcompany.com/ajax/fake-slug/clear-cache');
            curl_exec($ch);
            $response_info = curl_getinfo($ch);

            $success = ($response_info['http_code'] === 200);

            $full_success = $full_success && $success;
        }

        if ($full_success) {
            $this->delete();
        } else {
            $this->counter++;
            $this->save();
        }
    }
}
