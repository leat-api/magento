<?php

declare(strict_types=1);

namespace Leat\LoyaltyAdminUI\Block\Adminhtml\System\Config\Form\Field;

use Leat\LoyaltyAdminUI\Model\Config\Source\EarnIcons;
use Leat\LoyaltyAdminUI\Model\Config\Source\EarnRepeat;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Full-width dynamic rows for the earn points earnings.
 */
class Earnings extends AbstractFieldArray
{
    /**
     * @var ColumnSelect[]
     */
    private array $selects = [];

    public function __construct(
        Context $context,
        protected EarnIcons $earnIcons,
        protected EarnRepeat $earnRepeat,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Render the row over the full width: label on top, rows below, no separate label column.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $inheritRequired = $this->_isInheritCheckboxRequired($element);
        if ($element->getInherit() == 1 && $inheritRequired) {
            $element->setDisabled(true);
        }

        $html = '<td colspan="3" class="leat-earnings-full-width">'
            . '<div class="label"><strong>' . $this->escapeHtml($element->getLabel()) . '</strong></div>'
            . '<div data-mage-init=\'{"Leat_LoyaltyAdminUI/js/form/sortable-rows": {}}\'>'
            . $this->_getElementHtml($element)
            . '</div>'
            . '<div class="note">' . $this->escapeHtml(__('Drag a row (by the grip) to change the order on the loyalty page.')) . '</div>'
            . ($element->getComment() ? '<div class="note leat-earnings-description">' . $element->getComment() . '</div>' : '')
            . '</td>';

        if ($inheritRequired) {
            $html .= $this->_renderInheritCheckbox($element);
        }

        return $this->_decorateRowHtml($element, $html);
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    protected function _prepareToRender(): void
    {
        $this->addColumn('handle', ['label' => '']);
        $this->addColumn('icon', [
            'label' => __('Icon'),
            'renderer' => $this->getSelect('icon', $this->earnIcons),
        ]);
        $this->addColumn('title', ['label' => __('Title'), 'class' => 'required-entry']);
        $this->addColumn('button_text', ['label' => __('Button text')]);
        $this->addColumn('link', ['label' => __('Button link')]);
        $this->addColumn('points', [
            'label' => __('Points'),
            'class' => 'validate-digits validate-zero-or-greater',
            'style' => 'width: 70px',
        ]);
        $this->addColumn('repeat', [
            'label' => __('Repeat'),
            'renderer' => $this->getSelect('repeat', $this->earnRepeat),
        ]);
        $this->addColumn('key', ['label' => __('Key'), 'style' => 'width: 140px']);

        $this->_addAfter = false;
        $this->_addButtonLabel = (string) __('Add earning');
    }

    /**
     * The handle column is the standard Magento drag grip, it holds no input and posts nothing.
     *
     * @param string $columnName
     * @return string
     * @throws \Exception
     */
    public function renderCellTemplate($columnName)
    {
        return $columnName === 'handle'
            ? '<div class="draggable-handle" title="' . $this->escapeHtmlAttr(__('Drag to reorder')) . '"></div>'
            : parent::renderCellTemplate($columnName);
    }

    /**
     * @param DataObject $row
     * @return void
     * @throws LocalizedException
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $options = [];
        foreach (['icon', 'repeat'] as $column) {
            $value = $row->getData($column);
            if ($value !== null) {
                $options['option_' . $this->getSelect($column)->calcOptionHash($value)] = 'selected="selected"';
            }
        }

        $row->setData('option_extra_attrs', $options);
    }

    /**
     * @param string $column
     * @param OptionSourceInterface|null $source
     * @return ColumnSelect
     * @throws LocalizedException
     */
    private function getSelect(string $column, ?OptionSourceInterface $source = null): ColumnSelect
    {
        if (!isset($this->selects[$column])) {
            $this->selects[$column] = $this->getLayout()->createBlock(
                ColumnSelect::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            )->setOptions($source->toOptionArray());
        }

        return $this->selects[$column];
    }
}
