<?php

namespace App\Services;

use App\Models\AppSetting;

/**
 * Dynamic plan & pricing configuration.
 * Values are stored in app_settings ('plan_config') and fall back to the defaults below,
 * so nothing breaks if an admin has never saved settings.
 */
class PlanConfigService
{
    public const SETTING_KEY = 'plan_config';

    /** Feature flags that can be toggled per tier */
    public const FLAGS = [
        'pdf_export'         => 'PDF report export',
        'priority_inference' => 'Priority AI processing',
        'full_history'       => 'Full scan history & collections',
        'toxicity_alerts'    => 'Species toxicity alerts',
    ];

    public static function defaults(): array
    {
        return [
            'free' => [
                'name'        => 'Free Starter',
                'scan_limit'  => 3,
                'description' => '3 lifetime AI mushroom scans with permanent access to saved data',
                'features'    => [
                    '3 AI mushroom scans included',
                    'Full access to all your saved scans & history',
                    'Full access to personal collection',
                    'Access to mushroom species directory',
                    'Basic classification details',
                ],
                'flags' => [
                    'pdf_export'         => false,
                    'priority_inference' => false,
                    'full_history'       => true,
                    'toxicity_alerts'    => true,
                ],
            ],
            'monthly' => [
                'name'           => 'Premium Monthly',
                'amount'         => 149.00,
                'interval'       => 'month',
                'badge'          => null,
                'promo_banner'   => null,
                'discount_pct'   => null,
                'description'    => 'Unlimited mushroom scans, priority AI recognition & full database access',
                'features'       => [
                    'Unlimited AI mushroom scans',
                    'Priority inference processing',
                    'Full access to all saved collections & scan history',
                    'Species toxicity alerts & detailed field guide',
                    'Export scan & collection reports (PDF)',
                    'Cancel anytime without lock-in',
                ],
                'flags' => [
                    'pdf_export'         => true,
                    'priority_inference' => true,
                    'full_history'       => true,
                    'toxicity_alerts'    => true,
                ],
            ],
            'annual' => [
                'name'           => 'Premium Annual',
                'amount'         => 1299.00,
                'interval'       => 'year',
                'badge'          => 'Best Value (Save ~27%)',
                'promo_banner'   => null,
                'discount_pct'   => 27,
                'description'    => '12 months of unlimited mushroom scans with all premium benefits',
                'features'       => [
                    'All features of Premium Monthly',
                    'Save over 27% compared to monthly billing',
                    'Full 365 days of uninterrupted AI identification',
                    'Exclusive priority feature updates',
                    'Full access to all saved collections & scan history',
                    'Export scan & collection reports (PDF)',
                ],
                'flags' => [
                    'pdf_export'         => true,
                    'priority_inference' => true,
                    'full_history'       => true,
                    'toxicity_alerts'    => true,
                ],
            ],
        ];
    }

    /** Full config (defaults merged with saved overrides) */
    public static function all(): array
    {
        $saved    = AppSetting::getValue(self::SETTING_KEY, []) ?: [];
        $defaults = self::defaults();

        foreach ($defaults as $tier => $values) {
            if (isset($saved[$tier]) && is_array($saved[$tier])) {
                $merged          = array_merge($values, $saved[$tier]);
                $merged['flags'] = array_merge($values['flags'], $saved[$tier]['flags'] ?? []);
                $defaults[$tier] = $merged;
            }
        }

        return $defaults;
    }

    public static function save(array $config): array
    {
        AppSetting::setValue(self::SETTING_KEY, $config);
        return self::all();
    }

    public static function freeScanLimit(): int
    {
        return (int) (self::all()['free']['scan_limit'] ?? 3);
    }

    /** Paid plans in the shape PayMongoService / checkout / user UI already expect */
    public static function paidPlans(): array
    {
        $config = self::all();
        $plans  = [];

        foreach (['monthly', 'annual'] as $key) {
            $p = $config[$key];
            $plans[$key] = [
                'id'           => $key,
                'name'         => $p['name'],
                'amount'       => (float) $p['amount'],
                'centavos'     => (int) round(((float) $p['amount']) * 100),
                'currency'     => 'PHP',
                'interval'     => $p['interval'],
                'badge'        => $p['badge'] ?? null,
                'promo_banner' => $p['promo_banner'] ?? null,
                'discount_pct' => $p['discount_pct'] ?? null,
                'description'  => $p['description'],
                'features'     => $p['features'],
                'flags'        => $p['flags'],
            ];
        }

        return $plans;
    }

    /** Feature flags for a tier key: free | monthly | annual | admin */
    public static function flagsFor(string $tier): array
    {
        if ($tier === 'admin') {
            return array_fill_keys(array_keys(self::FLAGS), true);
        }
        return self::all()[$tier]['flags'] ?? self::all()['free']['flags'];
    }
}
