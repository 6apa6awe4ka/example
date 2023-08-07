<?php

namespace app\models\subscriptions\trials;

use app\components\db\ActiveRecordWithTest;
use app\models\subscriptions\Subscription;
use app\models\subscriptions\SubscriptionPrice;

/**
 * @property int $owner_id
 * @property int $payment_id
 * @property int $ab_id
 */
class SubscriptionTrial extends ActiveRecordWithTest
{
    /**
     * @inheritdoc
     */
    protected static function baseTableName()
    {
        return 'owners_subscriptions_trials';
    }

    public static function trialUserState(
        int $owner_id,
        int $inner_code
    )
    {
        $time = time();
        /** @var SubscriptionTrialAb $trial_ab */
        $trial_ab = SubscriptionTrialAb::find()
            ->andWhere(
                ['<', 'start_ts', $time]
            )
            ->andWhere(
                ['>', 'until_ts', $time]
            )
            ->orderBy('id DESC')
            ->one();

        if ($trial_ab) {
            $is_available = !static::find()
                ->where([
                    'owner_id' => $owner_id,
                ])
                ->exists();

            if ($is_available) {
                $case_id = -1;
                switch ($trial_ab->availability) {
                    case SubscriptionTrialAb::AVAILABILITY_ALL:
                        $case_id = $trial_ab->case_id;
                        break;
                    case SubscriptionTrialAb::AVAILABILITY_CLOSED:
                        /** @var SubscriptionTrialAbOwner $trial_owner */
                        $trial_owner = SubscriptionTrialAbOwner::find()
                            ->where([
                                'ab_id' => $trial_ab->id,
                                'owner_id' => $owner_id,
                            ])
                            ->one();
                        if ($trial_owner) {
                            $case_id = $trial_owner->case_id;
                        }
                        break;
                }

                if ($case_id > 0) {
                    /** @var SubscriptionTrialAbCase $trial_ab_case */
                    $trial_ab_case = SubscriptionTrialAbCase::find()
                        ->where([
                            'id' => $case_id,
                            'inner_code' => $inner_code,
                        ])
                        ->one();
                    if ($trial_ab_case) {
                        return [$trial_ab, $trial_ab_case->price_id, $trial_ab_case->pay_case];
                    }
                } elseif ($case_id === 0) {
                    switch ($inner_code) {
                        case Subscription::INNER_CODE_LIGHT:
                            return [$trial_ab, SubscriptionPrice::ID_LIGHT_1_WEEK, SubscriptionTrialAbCase::PAY_CASE_FULL_FREE];
                        case Subscription::INNER_CODE_PREMIUM:
                            return [$trial_ab, SubscriptionPrice::ID_PREMIUM_2_WEEKS, SubscriptionTrialAbCase::PAY_CASE_FULL_FREE];
                    }
                }
            }
        }

        return [null, 0, 0];
    }

    public static function getMonthByUntilTs(
        int $trial_until_ts
    )
    {
        $months = [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];
        $index = (int)gmdate('m', $trial_until_ts);
        return $months[$index];
    }
}
