<?php

declare(strict_types=1);

namespace Leat\AsyncQueue\Ui\Component\Form\Button;

use Leat\AsyncQueue\Api\RequestRepositoryInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * Shared by the buttons of the job and request edit pages. The page is told apart by the controller name,
 * so one class serves both.
 */
abstract class Generic implements ButtonProviderInterface
{
    public function __construct(
        protected readonly UrlInterface $urlBuilder,
        protected readonly RequestInterface $request,
        protected readonly AuthorizationInterface $authorization,
        protected readonly RequestRepositoryInterface $requestRepository
    ) {
    }

    /**
     * @return bool
     */
    protected function isJobPage(): bool
    {
        return $this->request->getControllerName() === 'job';
    }

    /**
     * @return int
     */
    protected function entityId(): int
    {
        return (int) $this->request->getParam('id');
    }

    /**
     * @return int
     */
    protected function jobId(): int
    {
        if ($this->isJobPage()) {
            return $this->entityId();
        }
        try {
            return (int) $this->requestRepository->get($this->entityId())->getJobId();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param string $do reset|retry|sync
     * @param bool $onJob act on the job even when on a request page (retry always runs the job)
     * @return string
     */
    protected function actionUrl(string $do, bool $onJob = false): string
    {
        $onJob = $onJob || $this->isJobPage();
        return $this->urlBuilder->getUrl(
            'leat_asyncqueue/' . ($onJob ? 'job' : 'request') . '/action',
            ['do' => $do, 'id' => $onJob ? $this->jobId() : $this->entityId()]
        );
    }
}
