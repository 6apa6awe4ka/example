<?php

namespace app\models\subscriptions;

use app\components\db\ActiveRecordWithTest;

/**
 * @property int $owner_id
 */
class OwnerAuthNotifications extends  ActiveRecordWithTest
{
    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions_auth_notifications';
    }

    public static function add(
        int $owner_id
    )
    {
        $model = new static();
        $model->owner_id = $owner_id;
        $model->save();
    }
}
