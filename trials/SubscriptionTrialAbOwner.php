<?php

namespace app\models\subscriptions\trials;

use app\components\db\ActiveRecordWithTest;

/**
 * @property int $ab_id
 * @property int $owner_id
 * @property int $case_id
 */
class SubscriptionTrialAbOwner extends ActiveRecordWithTest
{
    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions_trials_ab_owners';
    }
}