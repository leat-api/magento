<?php

declare(strict_types=1);

namespace Leat\LoyaltyAdminUI\Block\Adminhtml\System\Config\Form\Field;

use Magento\Framework\View\Element\Html\Select;

/**
 * Select renderer for a dynamic rows column: AbstractFieldArray hands over name and id as input name / input id.
 */
class ColumnSelect extends Select
{
    /**
     * @param string $value
     * @return $this
     */
    public function setInputName($value): static
    {
        return $this->setName($value);
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setInputId($value): static
    {
        return $this->setId($value);
    }
}
