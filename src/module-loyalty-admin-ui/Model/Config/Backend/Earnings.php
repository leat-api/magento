<?php

declare(strict_types=1);

namespace Leat\LoyaltyAdminUI\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\Exception\LocalizedException;

/**
 * Earn points rows. The key of a row is what a claim is locked on, so it must stay stable and unique:
 * an empty key is filled with the row id, which makes every key explicit (and editable) from the first save.
 */
class Earnings extends ArraySerialized
{
    public const string KEY_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = $this->getValue();
        if (is_array($value)) {
            unset($value['__empty']);
            $seen = [];
            foreach ($value as $rowId => $row) {
                $key = trim((string) ($row['key'] ?? '')) ?: (string) $rowId;
                if (!preg_match(self::KEY_PATTERN, $key)) {
                    throw new LocalizedException(__(
                        'The earning key "%1" is invalid. Use letters, numbers, underscores and dashes (max 64).',
                        $key
                    ));
                }
                if (isset($seen[$key])) {
                    throw new LocalizedException(__('The earning key "%1" is used more than once.', $key));
                }

                $seen[$key] = true;
                $value[$rowId]['key'] = $key;
            }
        }

        $this->setValue($value);

        return parent::beforeSave();
    }
}
