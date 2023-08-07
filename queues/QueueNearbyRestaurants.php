<?php

namespace app\models\subscriptions\queues;

use app\components\db\ActiveRecordWithTest;
use app\models\frontend\Restaurant;
use app\models\RestaurantsSphinxIndex;
use app\models\subscriptions\Subscription;
use app\models\subscriptions\SubscriptionRestaurant;
use app\models\subscriptions\SubscriptionRestaurantNearby;
use Yii;
use yii\sphinx\Query;

/**
 * @property int $id
 * @property int $promo_restaurant_id
 */
class QueueNearbyRestaurants extends ActiveRecordWithTest
{
    #TODO: можно добавить статус и фильтровать добавление по статусу
    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'queue_subscriptions_restaurants_nearby';
    }

    public static function add(
        int $promo_restaurant_id
    )
    {
        $model = new static();
        $model->promo_restaurant_id = $promo_restaurant_id;
        $model->save();
    }

    public function complete()
    {
        $offset = 0;
        $limit = 100;
        $max_matches = 10000;

        $restaurant = Restaurant::findOne([
            'id' => $this->promo_restaurant_id,
        ]);

        $rlat = deg2rad($restaurant->latitude);
        $rlon = deg2rad($restaurant->longitude);
        $rlat_str = str_replace(',', '.', (string)$rlat);
        $rlon_str = str_replace(',', '.', (string)$rlon);

        $sphinx_index_city = RestaurantsSphinxIndex::getSphinxIndex(
            $restaurant->mainCity->city_id,
            $restaurant->country_id
        );
        $sphinx_index_country = RestaurantsSphinxIndex::getSphinxIndex(
            0,
            $restaurant->country_id
        );
        $indexes = [$sphinx_index_city];
        if ($sphinx_index_country !== $sphinx_index_city) {
            $indexes[] = $sphinx_index_country;
        }

        $sphinx_db = Yii::$app->get('rests_sphinx');

        $selection_geodist = ["geodist({$rlat_str}, {$rlon_str}, rlat, rlon) AS distance"];

        foreach ($indexes as $sphinx_index) {
            $sphinx_restaurants_query = new Query();
            $sphinx_restaurants_query->from(
                $sphinx_index
            )
                ->select(['restaurant_id'])
                ->addSelect($selection_geodist)
                ->where(
                    ['<', 'distance', SubscriptionRestaurantNearby::RADIUS]
                )
                ->addOptions(['max_matches' => $max_matches]);

            $db_resources_write = SubscriptionRestaurantNearby::getDb();

            while ($offset < $max_matches) {
                $sphinx_restaurants = $sphinx_restaurants_query
                    ->limit($limit)
                    ->offset($offset)
                    ->all(
                        $sphinx_db
                    );

                if (!$sphinx_restaurants) {
                    break;
                }

                $rows = [];
                foreach ($sphinx_restaurants as $sphinx_restaurant) {
                    if ($sphinx_restaurant['restaurant_id'] == $this->promo_restaurant_id) {
                        continue;
                    }
                    $rows[] = [(int)$sphinx_restaurant['restaurant_id'], $this->promo_restaurant_id];
                }

                $command = $db_resources_write->createCommand()
                    ->batchInsert(
                        SubscriptionRestaurantNearby::tableName(),
                        ['restaurant_id', 'promo_restaurant_id'],
                        $rows
                    );
                $sql = str_replace("INSERT", "INSERT IGNORE", $command->sql);

                $db_resources_write->createCommand($sql)
                    ->execute();

                $offset += $limit;
            }
        }

        QueueNearbyRestaurantsCachesDrop::add(
            $this->promo_restaurant_id
        );

        $this->delete();
    }
}
