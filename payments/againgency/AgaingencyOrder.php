<?php

namespace app\models\subscriptions\payments\againgency;

use app\components\db\ActiveRecordWithTest;

/**
 * @property int $id
 * @property int subscription_id
 * @property ?string order_id
 * @property string external_id
 */
class AgaingencyOrder extends ActiveRecordWithTest
{
    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_payments_againgency_orders';
    }
}
