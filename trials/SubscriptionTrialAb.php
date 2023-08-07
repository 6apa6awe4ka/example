<?php

namespace app\models\subscriptions\trials;

use app\components\db\ActiveRecordWithTest;

/**
 * @property int $id
 * @property int $case_id
 * @property int $start_ts
 * @property int $until_ts
 * @property int $availability
 */
class SubscriptionTrialAb extends ActiveRecordWithTest
{
    const AVAILABILITY_ALL = 0;
    const AVAILABILITY_CLOSED = 1;
    const AVAILABILITY_MIXED = 2;

    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions_trials_ab';
    }
}
