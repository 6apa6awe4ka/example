<?php

namespace app\models\subscriptions;

use app\components\db\ActiveRecordWithTest;
use app\models\Agency;
use app\models\frontend\Restaurant;
use app\models\frontend\Url;
use app\models\RestaurantReview;

/**
 * @property int $id
 * @property int $subscription_id
 * @property int $restaurant_id
 * @property int $agency_id
 * @property int $review_id
 * @property int $lang_id
 */
class SubscriptionReview extends ActiveRecordWithTest
{
    const PER_RESTAURANT_MAX_COUNT = 3;

    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions_reviews';
    }

    /**
     * @return \yii\db\Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return \Yii::$app->get('db_resources_write');
    }

    public static function queryRestaurantReviews(
        int $subscription_id,
        int $restaurant_id
    )
    {
        return static::find()
            ->where([
                'subscription_id' => $subscription_id,
                'restaurant_id' => $restaurant_id,
            ])
            ->orderBy('id DESC')
            ->limit(static::PER_RESTAURANT_MAX_COUNT)
        ;
    }

    #TODO: перенести в таски
    protected static function dropCache(
        int $restaurant_id
    )
    {
        $url_model = Url::findOne([
            'object_type' => 'restaurant',
            'object_id' => $restaurant_id,
            'redirect_url_hash' => 0,
        ]);

        $url = "https://restcompany.com/{$url_model->url}?remcache=1";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);
        $response_info = curl_getinfo($ch);

        $success = $response_info['http_code'] === 200;

        return $success;
    }

    public static function bulkSave(
        int $owner_id,
        int $subscription_id,
        int $restaurant_id,
        ?array $for_deleting_ids,
        ?array $for_adding_data
    )
    {
        $count_deleted = 0;
        $count_added = 0;

        $owner_restaurant = SubscriptionRestaurant::find()
            ->where([
                'owner_id' => $owner_id,
                'subscription_id' => $subscription_id,
                'restaurant_id' => $restaurant_id,
            ])->andWhere(
                ['>', 'stop_timestamp', time()]
            );

        if (!$owner_restaurant) {
            return false;
        }

        if ($for_deleting_ids) {
            static::deleteAll(['and',
                [
                    'subscription_id' => $subscription_id,
                    'restaurant_id' => $restaurant_id,
                ],
                ['in', 'id', $for_deleting_ids],
            ]);
        }

        if ($for_adding_data) {
            if (count($for_adding_data) > static::PER_RESTAURANT_MAX_COUNT) {
                if ($count_deleted) {
                    static::dropCache(
                        $restaurant_id
                    );
                }
                return true;
            }

            $reviews_data_provider = new RestaurantReview();
            $restaurant = Restaurant::findOne(['id' => $restaurant_id]);
            $reviews_data_provider->setRestaurant($restaurant);

            $reviews_map = [];
            foreach ($for_adding_data as $review_data) {
                $agency_name = Agency::getAgencyName($review_data['agency_id']);
                if (!array_key_exists($agency_name, $reviews_map)) {
                    $reviews_map[$agency_name] = [];
                }
                $reviews_map[$agency_name][] = $review_data['review_id'];
            }

            $query_reviews = $reviews_data_provider->getSubQueries(
                $reviews_map
            );

            $reviews = $query_reviews->all(
                RestaurantReview::getDb()
            );

            foreach ($reviews as $review) {
                $owner_review = new static();
                $owner_review->subscription_id = $subscription_id;
                $owner_review->restaurant_id = $restaurant_id;
                $owner_review->agency_id = $review['agency_id'];
                $owner_review->review_id = $review['id'];
                $owner_review->lang_id = $review['lang_id'];
                $owner_review->save();
            }
            $count_added = count($reviews);
        }

        if ($count_deleted || $count_added) {
            static::dropCache(
                $restaurant_id
            );
        }

        return true;
    }
}
