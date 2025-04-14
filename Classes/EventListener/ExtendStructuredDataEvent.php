<?php

declare(strict_types=1);

namespace Zeroseven\PagebasedBlog\EventListener;

use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use Zeroseven\Pagebased\Event\StructuredDataEvent;
use Zeroseven\Pagebased\Exception\TypeException;
use Zeroseven\Pagebased\Registration\Registration;
use Zeroseven\Pagebased\Utility\CastUtility;
use Zeroseven\Pagebased\Utility\SettingsUtility;

class ExtendStructuredDataEvent
{
    protected ContentObjectRenderer $contentObjectRenderer;
    protected SiteFinder $siteFinder;
    protected ExtensionConfiguration $extensionConfiguration;

    public function __construct(
        ContentObjectRenderer $contentObjectRenderer,
        SiteFinder $siteFinder,
        ExtensionConfiguration $extensionConfiguration
    ) {
        $this->contentObjectRenderer = $contentObjectRenderer;
        $this->siteFinder = $siteFinder;
        $this->extensionConfiguration = $extensionConfiguration;
    }

    protected function forceAbsoluteUrl(mixed $parameter = null): ?string
    {
        try {
            return empty($parameter) ? null : $this->contentObjectRenderer->typoLink_URL([
                'parameter' => CastUtility::string($parameter),
                'forceAbsoluteUrl' => true
            ]);
        } catch (TypeException $e) {
            return null;
        }
    }

    protected function getRootPageUri(int $currentPageId): ?string
    {
        try {
            $rootPageUid = $this->siteFinder->getSiteByPageId($currentPageId)->getRootPageId();
        } catch (SiteNotFoundException $e) {
            return null;
        }

        return $this->forceAbsoluteUrl($rootPageUid);
    }

    protected function getPostPost(Registration $registration, int $uid): ?object
    {
        try {
            if ($registration->getExtensionName() === 'pagebased_blog') {
                return $registration->getObject()->getRepositoryClass()->findByUid($uid);
            }
        } catch (ResourceDoesNotExistException|RuntimeException $e) {
            return null;
        }

        return null;
    }

    /** @throws ResourceDoesNotExistException */
    public function __invoke(StructuredDataEvent $event): void
    {
        if (
            ($registration = $event->getRegistration())
            && ($uid = $event->getUid())
            && ($event->getRegistration()->getExtensionName() === 'pagebased_blog')
            && ($post = $this->getPostPost($registration, $uid))
        ) {
            $event->addPropertyType('', [
                '@context' => 'https://schema.org/',
                'headline' => $post->getTitle(),
                'url' => $this->forceAbsoluteUrl($post->getUid())
            ], 'BlogPosting');

            if ($identifier = SettingsUtility::getExtensionConfiguration($registration, 'structuredData.identifier')) {
                $event->addPropertyType('identifier', [
                    'name' => $identifier,
                    'value' => (string)$post->getUid()
                ], 'PropertyValue');
            } else {
                $settings = SettingsUtility::getExtensionConfiguration($registration);
                $settings['structuredData']['identifier'] = uniqid($registration->getExtensionName() . '-', false);
                $this->extensionConfiguration->set($registration->getExtensionName(), $settings);
            }

            if ($image = $post->getFirstImage()) {
                // @extensionScannerIgnoreLine
                $event->addProperty('image', $image);
            }

            if ($description = $post->getDescription()) {
                $event->addProperty('description', $description);
            }

            if ($dateModified = $post->getLastChangeDate() ?? $post->getCreateDate()) {
                $event->addProperty('dateModified', $dateModified->format('Y-m-d'));
            }

            if ($datePosted = ($post->getDate() ?? ($post->getAccessStartDate() ?? $post->getCreateDate()))) {
                $event->addProperty('datePublished', $datePosted->format('Y-m-d'));
            }

            if ($author = $post->getContact()) {
                $event->addPropertyType('author', [
                    'name' => $author->getFullName(),
                    'knowsAbout' => $author->getExpertise(),
                    'image' => $author->getImage(),
                    'sameAs' => [
                        $this->forceAbsoluteUrl($author->getTwitter()),
                        $this->forceAbsoluteUrl($author->getXing()),
                        $this->forceAbsoluteUrl($author->getLinkedin()),
                        $this->forceAbsoluteUrl($author->getPageLink())
                    ]
                ], 'Person');
            }

            if (($publisher = SettingsUtility::getPluginConfiguration($registration, 'settings.structuredData.publisher')) && !empty($publisher['name'])) {
                $event->addPropertyType('publisher', [
                    'name' => $publisher['name'],
                    'url' => $publisher['url'] ?? $this->getRootPageUri($post->getUid()),
                ], 'Organization');

                if ($logo = $publisher['logo'] ?? null) {
                    if (file_exists($absolutePath = GeneralUtility::getFileAbsFileName($logo))) {
                        $event->addPropertyType('publisher.logo', ['url' => $this->forceAbsoluteUrl(PathUtility::getAbsoluteWebPath($absolutePath))], 'ImageObject');
                    } else {
                        throw new ResourceDoesNotExistException('Creating structured data of the blog post failed. The organization logo ("' . $logo . '") does not exist.', 1689283412);
                    }
                }
            }
        }
    }
}
