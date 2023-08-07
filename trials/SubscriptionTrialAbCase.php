<?php

namespace app\models\subscriptions\trials;

use app\components\db\ActiveRecordWithTest;

/**
 * @property int $id
 * @property int $inner_code
 * @property int $price_id
 * @property int $pay_case
 */
class SubscriptionTrialAbCase extends ActiveRecordWithTest
{
    const PAY_CASE_FULL_FREE = 0;
    const PAY_CASE_1_RECURRING_DOLLAR = 1;

    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions_trials_ab_case';
    }
}
