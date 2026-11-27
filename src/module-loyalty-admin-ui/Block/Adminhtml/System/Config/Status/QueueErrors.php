<?php

declare(strict_types=1);

namespace Leat\LoyaltyAdminUI\Block\Adminhtml\System\Config\Status;

use Leat\AsyncQueue\Model\RequestStatus;
use Leat\AsyncQueue\Service\JobDigest;
use Leat\LoyaltyAdminUI\Block\Adminhtml\System\Config\GenericField;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Banner at the top of the Leat configuration: requests that have hit an error and are not done yet.
 * - A request that is listed has failed at least once. It is not necessarily out of retries (see the badge).
 * - Requests of completed jobs are left out: the queue never runs them again.
 * - On a website or store scope only the jobs of that scope are listed.
 */
class QueueErrors extends GenericField
{
    private const LIMIT = 25;
    private const REASON_LENGTH = 160;

    public function __construct(
        protected ResourceConnection $resource,
        protected AuthorizationInterface $authorization,
        RequestInterface $request,
        StoreManagerInterface $storeManager,
        Context $context,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        parent::__construct($request, $storeManager, $context, $data, $secureRenderer);
    }

    /**
     * The group has no fields: this block is its whole content.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        return $this->css() . $this->getBanner();
    }

    /**
     * Coloured banner: red when a request is out of retries, yellow while requests are failing, green when none.
     * Below the summary, for admins with queue access, the failing requests in a table: the latest failure and
     * its date are the first row.
     *
     * @return string
     */
    private function getBanner(): string
    {
        $connection = $this->resource->getConnection();
        $storeIds = $this->getScopeStoreIds();

        $select = function () use ($connection, $storeIds) {
            $select = $connection->select()
                ->from(['r' => $this->resource->getTableName('async_queue_request')], [])
                ->join(['j' => $this->resource->getTableName('async_queue_job')], 'j.job_id = r.job_id', [])
                ->where('j.completed = 0')
                ->where(RequestStatus::failingSql('r'));
            if ($storeIds) {
                $select->where('j.store_id IN (?)', $storeIds);
            }
            return $select;
        };

        $counts = $connection->fetchRow(
            $select()->columns([
                'failing' => 'COUNT(*)',
                'exhausted' => 'COALESCE(SUM' . RequestStatus::exhaustedSql('r') . ', 0)',
            ])
        );
        $failing = (int) $counts['failing'];
        $exhausted = (int) $counts['exhausted'];

        $header = $this->escapeHtml(__('Leat queue'));
        if (!$failing) {
            return $this->container('success', $header, $this->escapeHtml(__('No failing requests.')));
        }

        $canOpen = $this->authorization->isAllowed('Leat_AsyncQueue::jobs');
        $link = fn(array $row, string $text) => $canOpen
            ? '<a href="' . $this->escapeUrl($this->getUrl('leat_asyncqueue/job/edit', ['id' => $row['job_id']])) . '">'
                . $this->escapeHtml($text) . '</a>'
            : $this->escapeHtml($text);

        $summary = $this->escapeHtml(__('%1 request(s) failing', $failing))
            . ($exhausted
                ? ', <strong>' . $this->escapeHtml(__('%1 need admin intervention', $exhausted)) . '</strong>'
                : '')
            . ($canOpen
                ? ' &mdash; <a href="' . $this->escapeUrl($this->getUrl('leat_asyncqueue/job/index', ['attention' => 1]))
                    . '">' . $this->escapeHtml(__('Open the jobs list')) . '</a>'
                : '')
            . '.';

        // Counts are fine for everyone who sees the config; the failure details (API errors can hold customer data)
        // are only for admins who may view the queue.
        $content = '<p>' . $summary . '</p>';
        if ($canOpen) {
            $rows = $connection->fetchAll(
                $select()->columns([
                    'r.request_id', 'r.job_id', 'r.type_code', 'r.attempt', 'r.updated_at',
                    'reason' => 'LEFT(SUBSTRING_INDEX(r.latest_fail_reason, CHAR(10), 1), ' . self::REASON_LENGTH . ')',
                ])->order('r.updated_at DESC')->limit(self::LIMIT)
            );
            $content .= $this->table($rows, $failing, $link);
        }

        return $this->container($exhausted ? 'error' : 'warning', $header, $content);
    }

    /**
     * @param string $type error|warning|success
     * @param string $header escaped
     * @param string $content escaped html
     * @return string
     */
    private function container(string $type, string $header, string $content): string
    {
        return '<div class="leat-qe-container leat-qe-' . $type . '"><div class="leat-qe-header">' . $header . '</div>'
            . '<div class="leat-qe-content">' . $content . '</div></div>';
    }

    /**
     * The failing requests, most recent first. Every cell links to the job, so the whole row is clickable.
     *
     * @param array $rows
     * @param int $failing
     * @param callable $link
     * @return string
     */
    private function table(array $rows, int $failing, callable $link): string
    {
        $table = '<table class="leat-qe-table"><thead><tr>';
        foreach ([__('Request'), __('Type'), __('Job'), __('Attempts'), __('Latest failure'), __('Date of latest failure')] as $head) {
            $table .= '<th>' . $this->escapeHtml($head) . '</th>';
        }
        $table .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $exhausted = (int) $row['attempt'] >= JobDigest::MAX_ATTEMPTS;
            $table .= '<tr>'
                . '<td>' . $link($row, '#' . $row['request_id']) . '</td>'
                . '<td>' . $link($row, (string) $row['type_code']) . '</td>'
                . '<td>' . $link($row, '#' . $row['job_id']) . '</td>'
                . '<td' . ($exhausted ? ' class="leat-qe-bad"' : '') . '>'
                    . $link($row, sprintf('%d/%d', $row['attempt'], JobDigest::MAX_ATTEMPTS)) . '</td>'
                . '<td>' . $link($row, (string) $row['reason']) . '</td>'
                . '<td class="leat-qe-nowrap">' . $link($row, $this->formatFailureDate((string) $row['updated_at'])) . '</td>'
                . '</tr>';
        }
        $table .= '</tbody></table>';
        if ($failing > self::LIMIT) {
            $table .= '<p>' . $this->escapeHtml(__('Showing the %1 most recent of %2.', self::LIMIT, $failing)) . '</p>';
        }

        return $table;
    }

    /**
     * Store ids the listing is limited to, null for everything (default scope).
     *
     * @return int[]|null
     */
    private function getScopeStoreIds(): ?array
    {
        $scopeId = $this->getScopeId();
        return match ($this->getScope()) {
            'store' => [$scopeId],
            'website' => array_map('intval', $this->storeManager->getWebsite($scopeId)->getStoreIds()),
            default => null,
        };
    }

    /**
     * @param string $utc
     * @return string date in the admin's timezone
     */
    private function formatFailureDate(string $utc): string
    {
        return $this->_localeDate->formatDateTime(
            new \DateTime($utc, new \DateTimeZone('UTC')),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }

    /**
     * Same look as the Amasty license banner: tinted box and an icon in front of the header.
     *
     * @return string
     */
    private function css(): string
    {
        $icon = fn(string $svg) => "url(\"data:image/svg+xml,"
            . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20">' . $svg . '</svg>')
            . "\")";
        $warning = $icon('<path d="M10 1.5 19.5 18h-19z" fill="#ffc600"/><path d="M9.2 7h1.6l-.3 6h-1zM10 14.2a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8" fill="#000"/>');
        $error = $icon('<circle cx="10" cy="10" r="9" fill="#e22626"/><path d="M9.2 5h1.6l-.3 6h-1zM10 12.2a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8" fill="#fff"/>');
        $success = $icon('<circle cx="10" cy="10" r="9" fill="#2e9d3a"/><path d="m5.5 10.2 3 3 6-6.4" fill="none" stroke="#fff" stroke-width="2"/>');

        return '<style>
            .leat-qe-container { margin-bottom: 1.5rem; padding: 2rem 3rem; }
            .leat-qe-header { font-size: 1.7rem; font-weight: 600; margin-bottom: 1.3rem; }
            .leat-qe-header:before { margin-right: .6rem; vertical-align: sub; }
            .leat-qe-content { font-size: 1.4rem; font-weight: 400; }
            .leat-qe-content p { margin: 0 0 .8rem; }
            .leat-qe-warning { background-color: #fdf3da; }
            .leat-qe-warning .leat-qe-header:before { content: ' . $warning . '; }
            .leat-qe-error { background-color: #fceaea; }
            .leat-qe-error .leat-qe-header:before { content: ' . $error . '; }
            .leat-qe-success { background-color: #efffe2; }
            .leat-qe-success .leat-qe-header:before { content: ' . $success . '; }
            .leat-qe-date { color: #514943; white-space: nowrap; }
            .leat-qe-table { border-collapse: collapse; margin-top: 1.2rem; width: 100%; }
            .leat-qe-table th, .leat-qe-table td { padding: .5rem 1.5rem .5rem 0; text-align: left; vertical-align: top; }
            .leat-qe-table th { border-bottom: 1px solid rgba(0, 0, 0, .15); }
            .leat-qe-table tbody tr:hover { background: rgba(255, 255, 255, .6); }
            .leat-qe-table td a { color: inherit; display: block; text-decoration: none; }
            .leat-qe-bad, .leat-qe-bad a { color: #e22626; font-weight: 600; }
            .leat-qe-nowrap { white-space: nowrap; }
        </style>';
    }
}
