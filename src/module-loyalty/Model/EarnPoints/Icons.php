<?php

declare(strict_types=1);

namespace Leat\Loyalty\Model\EarnPoints;

/**
 * Icons available for earn points cards.
 *
 * Each name maps to an SVG in Leat_LoyaltyFrontend: view/frontend/web/images/earn-points/<name>.svg, resolved through
 * the theme fallback, so a theme overrides it at <theme>/Leat_LoyaltyFrontend/web/images/earn-points/<name>.svg.
 *
 * More icons are added without touching this module: put the SVG in a theme under that same path (icons are looked up
 * as Leat_LoyaltyFrontend::images/earn-points/<name>.svg, so only a theme can supply them) and register the name in
 * the di.xml of a custom module:
 *
 *   <type name="Leat\Loyalty\Model\EarnPoints\Icons">
 *       <arguments>
 *           <argument name="names" xsi:type="array">
 *               <item name="pinterest" xsi:type="string">pinterest</item>
 *           </argument>
 *       </arguments>
 *   </type>
 */
class Icons
{
    public const string DEFAULT = 'generic';

    public const array BUILT_IN = [
        // general
        'cart', 'birthday', 'gift', 'heart', 'review', 'share', 'newsletter', 'generic',
        // social media
        'instagram', 'facebook', 'x', 'tiktok', 'youtube', 'pinterest', 'linkedin', 'whatsapp', 'snapchat',
        'twitch', 'telegram', 'spotify',
    ];

    /**
     * Names end up in a file path: lowercase letters, digits and dashes only.
     */
    private const string NAME_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';

    /**
     * @var string[]
     */
    private array $names;

    /**
     * @param string[] $names Extra icon names, added to the built-in ones
     */
    public function __construct(array $names = [])
    {
        $extra = array_filter($names, static fn ($name) => is_string($name) && preg_match(self::NAME_PATTERN, $name));
        $this->names = array_values(array_unique([...self::BUILT_IN, ...$extra]));
    }

    /**
     * @return string[]
     */
    public function getNames(): array
    {
        return $this->names;
    }

    /**
     * The name when it is a known icon, otherwise the default icon.
     *
     * @param string $name
     * @return string
     */
    public function normalize(string $name): string
    {
        return in_array($name, $this->names, true) ? $name : self::DEFAULT;
    }
}
