<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Block\Adminhtml\Job;

use Leat\AsyncQueue\Api\JobRepositoryInterface;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Link from the job form to the customer the job belongs to.
 */
class CustomerLink extends Template
{
    public function __construct(
        Context $context,
        private readonly JobRepositoryInterface $jobRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array{id: string, label: string, url: string|null}|null null when the job has no relation
     */
    public function getCustomer(): ?array
    {
        try {
            $relationId = $this->jobRepository->get((int) $this->getRequest()->getParam('id'))->getRelationId();
        } catch (NoSuchEntityException) {
            return null;
        }
        if ($relationId === null || !ctype_digit($relationId)) {
            return null;
        }

        try {
            $customer = $this->customerRepository->getById((int) $relationId);
        } catch (NoSuchEntityException) {
            return ['id' => $relationId, 'label' => (string) __('Customer #%1 (no longer exists)', $relationId), 'url' => null];
        }

        return [
            'id' => $relationId,
            'label' => sprintf('%s %s <%s> (#%s)', $customer->getFirstname(), $customer->getLastname(), $customer->getEmail(), $relationId),
            'url' => $this->getUrl('customer/index/edit', ['id' => $relationId]),
        ];
    }
}
