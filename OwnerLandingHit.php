<?php

namespace app\models\subscriptions;

use app\components\db\ActiveRecordWithTest;
use Yii;

/**
 * @property int $id
 * @property int $owner_id
 * @property int $ip2long
 * @property string $user_agent
 * @property int $timestamp
 */
class OwnerLandingHit extends ActiveRecordWithTest
{
    protected static function baseTableName()
    {
        return 'owners_subscriptions_landing_hits';
    }

    /**
     * @return \yii\db\Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return Yii::$app->get('db_userdata');
    }
}
