<?php

declare(strict_types=1);

namespace Leat\LoyaltyFrontend\Block\Widget;

use Leat\AsyncQueue\Model\Queue\Request\RequestTypePool;
use Leat\Loyalty\Model\Config;
use Leat\Loyalty\Model\Connector;
use Leat\Loyalty\Model\EarnPoints\Icons;
use Leat\Loyalty\Model\EarnPoints\Repeat;
use Leat\Loyalty\Model\ResourceModel\EarnClaim;
use Leat\Loyalty\Model\ResourceModel\Loyalty\ContactResource;
use Leat\LoyaltyFrontend\Block\GenericWidgetBlock;
use Magento\Customer\Model\Session;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;

class EarnPoints extends GenericWidgetBlock
{
    protected const string LOGGER_PURPOSE = 'earn_points';

    protected $_template = "Leat_LoyaltyFrontend::widget/earn_points.phtml";

    /**
     * @var string
     */
    protected string $defaultId = 'leat-earn-points';

    /**
     * @var string
     */
    protected string $defaultCssClass = 'leat-earn-points-container';

    public function __construct(
        StoreManagerInterface $storeManager,
        Config $config,
        Session $customerSession,
        ContactResource $contactResource,
        Connector $connector,
        RequestTypePool $requestTypePool,
        Context $context,
        protected EarnClaim $earnClaim,
        protected TimezoneInterface $timezone,
        protected Repository $assetRepository,
        protected Icons $icons,
        /** @var string[] rendered SVG per icon name, so cards sharing an icon read the file once */
        protected array $iconHtml = []
    ) {
        parent::__construct(
            $storeManager,
            $config,
            $customerSession,
            $contactResource,
            $connector,
            $requestTypePool,
            $context
        );
    }

    /**
     * @return string
     */
    public function getWidgetHeading(): string
    {
        return (string) ($this->getData('widget_heading')
            ?: $this->config->getEarnPointsWidgetHeading($this->getStoreId())
            ?: __('Earn points'));
    }

    /**
     * @return string
     */
    public function getCompletedLabel(): string
    {
        return $this->config->getEarnPointsCompletedLabel($this->getStoreId()) ?: (string) __('Completed');
    }

    /**
     * Name of the points, as configured for the credits display
     *
     * @return string
     */
    public function getPointsName(): string
    {
        return (string) ($this->config->getCreditName($this->getStoreId()) ?: __('points'));
    }

    /**
     * @return string
     */
    public function getClaimUrl(): string
    {
        return $this->getUrl('leat/earnpoints/claim');
    }

    /**
     * Cards to render. A card with 0 points is a plain link: it can't be claimed, so it is never completed.
     *
     * @return array[] list of [id, title, button_text, link, points, completed, icon_html]
     */
    public function getCards(): array
    {
        if (!$this->show()) {
            return [];
        }

        try {
            $claimed = array_flip($this->earnClaim->getClaimedKeys((int) $this->customerSession->getCustomerId()));
            $now = $this->timezone->date();
            $cards = [];
            foreach ($this->config->getEarnPointsEarnings($this->getStoreId()) as $earning) {
                $key = $earning['id'] . '|' . Repeat::periodKey($earning['repeat'], $now);
                $cards[] = [
                    'id' => $earning['id'],
                    'title' => $earning['title'],
                    'button_text' => $earning['button_text'],
                    'link' => $earning['link'],
                    'points' => $earning['points'],
                    'completed' => $earning['points'] > 0 && isset($claimed[$key]),
                    'icon_html' => $this->getIconHtml($earning['icon']),
                ];
            }

            return $cards;
        } catch (\Throwable $e) {
            $this->getLogger()->log($e->getMessage());
        }

        return [];
    }

    /**
     * Inline SVG of an icon (so it can take the text colour). The file is resolved through the theme fallback:
     * a theme overrides it at <theme>/Leat_LoyaltyFrontend/web/images/earn-points/<name>.svg.
     * Unknown names, and registered names without a file, get the default icon.
     *
     * @param string $icon
     * @return string
     */
    public function getIconHtml(string $icon): string
    {
        $icon = $this->icons->normalize($icon);
        if (isset($this->iconHtml[$icon])) {
            return $this->iconHtml[$icon];
        }

        try {
            $html = $this->assetRepository->createAsset(
                'Leat_LoyaltyFrontend::images/earn-points/' . $icon . '.svg',
                ['area' => 'frontend']
            )->getContent();
        } catch (\Throwable $e) {
            $this->getLogger()->log(sprintf('Earn points icon "%s" has no SVG file: %s', $icon, $e->getMessage()));

            $html = $icon === Icons::DEFAULT ? '' : $this->getIconHtml(Icons::DEFAULT);
        }

        return $this->iconHtml[$icon] = $html;
    }
}
